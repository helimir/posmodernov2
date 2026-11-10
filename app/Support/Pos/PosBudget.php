<?php

namespace App\Support\Pos;

use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;
use App\Models\FiscalProfile;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El PRESUPUESTO de la caja: lo que se va a cobrar, SIN cobrarlo.
 *
 * REGLA que fija esta clase: *el total con impuesto lo calcula el MOTOR, y
 * el navegador no lo replica*. El precio con impuesto depende de la
 * RESOLUCION de la categoria fiscal y de la tasa vigente, y esa resolucion es
 * del dominio: replicarla en el navegador es una segunda verdad, y las dos
 * divergen. El legacy multiplicaba el impuesto por el precio de catalogo en
 * vez de por el precio cobrado (defecto P0 #13 del mapa funcional), y por eso
 * el impuesto salia mal.
 *
 * QUE HACE, Y POR QUE:
 *
 *  - RESUELVE el perfil fiscal de la serie del documento, su moneda y su
 *    contexto: es el mismo contexto que usa el checkout, y el total sale del
 *    mismo motor con la misma regla de redondeo.
 *  - RESUELVE la categoria fiscal y la tasa VIGENTE de cada linea, con el
 *    MISMO resolvedor que usa el dominio (`TaxRateResolver`).
 *  - CALCULA con el motor (`TaxCalculator`), que es PURO: aqui no se escribe
 *    nada. Un presupuesto no es una venta: no reserva numero, no consume
 *    stock y no escribe asiento.
 *
 * LO QUE NO HACE, Y POR QUE:
 *
 *  - NO toca el dominio: `TaxCalculator`, `TaxRateResolver` y
 *    `CheckoutService` se orquestan, no se modifican.
 *  - NO cobra: `CheckoutService` es el unico que escribe una venta.
 *
 * LA PRECEDENCIA DE LA CATEGORIA FISCAL, Y POR QUE SE REPITE AQUI:
 *
 * La precedencia es la del dominio: el override de la linea, el override del
 * puente de la tienda y, si no hay, la categoria del producto. El dominio la
 * tiene en un metodo PRIVADO (`CheckoutService::taxCategory`), y el dominio no
 * se toca en esta unidad, asi que aqui se resuelve la misma regla.
 *
 * ESA REPETICION ESTA ATADA POR UNA PRUEBA, NO POR LA CONFIANZA: el test
 * `test_el_total_del_presupuesto_coincide_con_el_grand_total_de_la_venta`
 * comprueba que el total del presupuesto es EXACTAMENTE el `grand_total` de la
 * venta que cobra el mismo carrito. Si las dos mitades se separan, ese test
 * falla. Un presupuesto que no coincide con el cobro es un presupuesto
 * mentiroso.
 */
final readonly class PosBudget
{
    public function __construct(
        private Connection $connection,
        private TaxRateResolver $rates,
    ) {}

    /**
     * El PRESUPUESTO de un carrito: lo que se cobra, con su desglose.
     *
     * @param  int  $storeId  La tienda donde se cobra.
     * @param  int  $documentSeriesId  La serie del documento: de ella sale el
     *                                 perfil fiscal, su moneda y su contexto.
     * @param  DateTimeImmutable  $issuedAt  El instante de emision: la tasa
     *                                       vigente se resuelve para esta
     *                                       fecha.
     * @param  list<CheckoutLineInput>  $lines  Las MISMAS lineas que se
     *                                          cobrarian: el presupuesto y
     *                                          el cobro reciben el mismo
     *                                          objeto, y por eso sus
     *                                          numeros coinciden.
     * @return array{subtotal: string, tax_total: string, discount: string, rounding_adjustment: string, grand_total: string}
     *
     * @throws RuntimeException Cuando falta el perfil, la moneda, la categoria
     *                          o la tasa vigente: sin ellos no hay total, y
     *                          un total sin impuesto es peor que ningun
     *                          total.
     */
    public function quote(
        int $storeId,
        int $documentSeriesId,
        DateTimeImmutable $issuedAt,
        array $lines
    ): array {
        $profile = $this->profile($documentSeriesId);
        $currency = $profile->defaultCurrency;

        if ($currency === null) {
            throw new RuntimeException(
                "El perfil fiscal {$profile->id} no tiene moneda: no se puede calcular el impuesto del presupuesto."
            );
        }

        $context = $profile->taxContext($currency);

        $taxLines = [];
        $discount = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        foreach ($lines as $line) {
            $categoryId = $this->taxCategory($line, $storeId);
            $rate = $this->rates->resolve($categoryId, $issuedAt);

            if ($rate === null) {
                throw new RuntimeException(
                    "La categoria fiscal {$categoryId} no tiene ninguna tasa vigente el "
                    .$issuedAt->format('Y-m-d').': no se cobra en silencio una linea sin impuesto.'
                );
            }

            $taxLines[] = new TaxLineInput(
                taxCategoryId: $categoryId,
                taxRateId: $rate['id'],
                // El porcentaje CONGELADO, como en el checkout: un cambio
                // posterior de la tasa vigente no altera este total.
                taxRateSnapshot: $rate['percentage'],
                evidenceStatus: $rate['evidence_status'],
                method: $context->taxMethod(),
                // El precio COBRADO, no el de catalogo.
                unitPrice: $line->unitPrice,
                quantity: $line->quantity,
                discount: $line->discount,
            );

            $discount = $discount->plus($line->discount)->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
        }

        $result = (new TaxCalculator($context))->calculate(new TaxDocumentInput(
            lines: $taxLines,
            documentDiscount: '0',
            issuedAt: $issuedAt,
        ));

        return [
            // La base imponible neta: el precio cobrado por la cantidad, menos
            // el descuento de la linea. Es la misma cifra que escribira
            // `sale_totals.sub_total` cuando se cobre.
            'subtotal' => $result->subtotal,
            'tax_total' => $result->taxTotal,
            // El descuento que el cajero aplico: la suma de los descuentos de
            // las lineas. El motor lo resta de la base antes de calcular el
            // impuesto, y aqui se enseña CUANTO se desconto.
            'discount' => (string) $discount,
            'rounding_adjustment' => $result->roundingAdjustment,
            'grand_total' => $result->grandTotal,
        ];
    }

    /**
     * El perfil fiscal de la serie, con su moneda y sus claves.
     */
    private function profile(int $documentSeriesId): FiscalProfile
    {
        $series = $this->connection->table('fiscal_series')
            ->where('id', $documentSeriesId)
            ->first();

        if ($series === null) {
            throw new RuntimeException("La serie de documento {$documentSeriesId} no existe.");
        }

        $model = (new FiscalProfile)->setConnection($this->connection->getName());

        $profile = $model->newQuery()
            ->with(['defaultCurrency', 'fields'])
            ->find((int) $series->fiscal_profile_id);

        if ($profile === null) {
            throw new RuntimeException(
                'El perfil fiscal de la serie '.$documentSeriesId.' no existe.'
            );
        }

        return $profile;
    }

    /**
     * La categoria fiscal de una linea en una tienda.
     *
     * La precedencia es la del dominio: el override de la linea, el override
     * del puente de la tienda y, si no hay, la categoria del producto. Sin
     * categoria no hay tratamiento fiscal: la linea no se cobra en silencio.
     */
    private function taxCategory(CheckoutLineInput $line, int $storeId): int
    {
        if ($line->taxCategoryId !== null) {
            return $line->taxCategoryId;
        }

        $deLaTienda = $this->connection->table('product_stores')
            ->where('product_id', $line->productId)
            ->where('store_id', $storeId)
            ->value('tax_category_id');

        if ($deLaTienda !== null) {
            return (int) $deLaTienda;
        }

        $delProducto = $this->connection->table('products')
            ->where('id', $line->productId)
            ->value('tax_category_id');

        if ($delProducto !== null) {
            return (int) $delProducto;
        }

        throw new RuntimeException(
            "El producto {$line->productId} no tiene categoria fiscal en la tienda {$storeId}: "
            .'no se cobra en silencio una linea sin tratamiento fiscal.'
        );
    }
}
