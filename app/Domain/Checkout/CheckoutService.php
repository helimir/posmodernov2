<?php

namespace App\Domain\Checkout;

use App\Domain\Accounting\LedgerWriter;
use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxDocumentResult;
use App\Domain\Tax\TaxLineInput;
use App\Domain\Tax\TaxRateResolver;
use App\Models\FiscalProfile;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El checkout: T1 del dominio.
 *
 * Una venta es UNA unidad atomica. TODO el cuerpo del metodo vive dentro de
 * UNA transaccion: la reserva del numero de documento, la del numero de
 * control, el consumo FIFO del stock, la cabecera, las lineas, los totales,
 * el asiento contable y los pagos. Si algo falla a mitad, no queda nada
 * escrito y NINGUN numero se quema (design §B.1, `esquema-dominio`
 * §"Atomicidad de toda escritura").
 *
 * La reserva del numero de CONTROL va DENTRO de la misma transaccion que la
 * del numero de documento. Si el rango de control esta agotado, la excepcion
 * revierte T1 entera y el numero del documento TAMBIEN vuelve: el documento
 * y su control son un solo hecho, no dos.
 *
 * El motor de impuestos se construye DENTRO, con el contexto que sale del
 * perfil de la serie del documento: su contexto depende de la tienda, asi
 * que no puede ser una dependencia fija del servicio.
 *
 * Las cuentas contables son DATO de la entrada (`CheckoutInput::$accounts`):
 * el dominio no inventa un plan de cuentas.
 */
final readonly class CheckoutService
{
    public function __construct(
        private Connection $connection,
        private NumberSeries $numberSeries,
        private ControlNumberGenerator $controlNumberGenerator,
        private TaxRateResolver $taxRateResolver,
        private LotConsumer $lotConsumer,
        private LedgerWriter $ledgerWriter,
    ) {}

    /**
     * Cobra la venta y devuelve lo que la caja necesita para imprimir.
     *
     * @throws RuntimeException Cuando falta una configuracion (serie,
     *                          perfil, moneda, categoria o tasa vigente) o
     *                          cuando la operacion no puede completarse.
     */
    public function checkout(CheckoutInput $input): CheckoutResult
    {
        return $this->connection->transaction(function () use ($input): CheckoutResult {
            // 1. EL PERFIL FISCAL Y EL CONTEXTO. El perfil sale de la serie
            //    del documento, y el contexto del motor sale del perfil.
            $series = $this->connection->table('fiscal_series')
                ->where('id', $input->documentSeriesId)
                ->first();

            if ($series === null) {
                throw new RuntimeException(
                    "La serie de documento {$input->documentSeriesId} no existe."
                );
            }

            $profile = $this->profile((int) $series->fiscal_profile_id);
            $currency = $profile->defaultCurrency;

            if ($currency === null) {
                throw new RuntimeException(
                    "El perfil fiscal {$profile->id} no tiene moneda: no se puede calcular el impuesto de la venta."
                );
            }

            $context = $profile->taxContext($currency);

            // 2. EL NUMERO DEL DOCUMENTO. La reserva es atomica y su traza
            //    queda en `fiscal_document_reservations`.
            $documentNumber = $this->numberSeries->reserve($input->documentSeriesId, $input->actorId);
            // El prefijo lo DECLARA la serie, no este codigo.
            $invoiceNo = $this->documentNumber($series->prefix, $documentNumber);

            // 3. EL NUMERO DE CONTROL, DENTRO de la misma transaccion. Si su
            //    rango esta agotado, la excepcion revierte T1 entera y el
            //    numero del documento tambien vuelve.
            $controlNumber = null;

            if ($input->controlSeriesId !== null) {
                $controlNumber = $this->controlNumberGenerator
                    ->assign($input->controlSeriesId, $input->issuedAt)
                    ->controlNumber;
            }

            // 4 y 5. LA CATEGORIA FISCAL Y LA TASA VIGENTE de cada linea. Una
            //    linea sin tratamiento fiscal NO se cobra en silencio: es un
            //    hueco de configuracion y falla.
            $taxLines = [];

            foreach ($input->lines as $line) {
                $categoryId = $this->taxCategory($line, $input->storeId);
                $rate = $this->taxRateResolver->resolve($categoryId, $input->issuedAt);

                if ($rate === null) {
                    throw new RuntimeException(
                        "La categoria fiscal {$categoryId} no tiene ninguna tasa vigente el "
                        .$input->issuedAt->format('Y-m-d').': no se cobra en silencio una linea sin impuesto.'
                    );
                }

                $taxLines[] = new TaxLineInput(
                    taxCategoryId: $categoryId,
                    taxRateId: $rate['id'],
                    // El porcentaje CONGELADO: un cambio posterior de la tasa
                    // vigente no altera este documento.
                    taxRateSnapshot: $rate['percentage'],
                    evidenceStatus: $rate['evidence_status'],
                    // El metodo del impuesto lo decide el perfil, no la linea.
                    method: $context->taxMethod(),
                    // El precio COBRADO, no el de catalogo.
                    unitPrice: $line->unitPrice,
                    quantity: $line->quantity,
                    discount: $line->discount,
                );
            }

            // 6. EL CALCULO. El motor es PURO: aqui no se escribe nada.
            $calculator = new TaxCalculator($context);
            $result = $calculator->calculate(new TaxDocumentInput(
                $taxLines,
                $input->documentDiscount,
                $input->issuedAt,
            ));

            // 7. LA CABECERA. `fiscal_document_id` queda en `null`: lo cierra
            //    T2, la emision fiscal.
            $status = $this->paymentStatus($input->payments, $result->grandTotal);

            $saleId = (int) $this->connection->table('sales')->insertGetId([
                'store_id' => $input->storeId,
                'customer_id' => $input->customerId,
                'invoice_no' => $invoiceNo,
                'sale_date' => $input->businessDate->format('Y-m-d'),
                'status' => $status,
                'created_by' => $input->actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 8. LOS ITEMS. El total y la base de la linea salen del motor,
            //    linea a linea y en el MISMO orden.
            $items = [];

            foreach ($input->lines as $index => $line) {
                $engine = $result->lines[$index];

                $itemId = (int) $this->connection->table('sale_items')->insertGetId([
                    'sale_id' => $saleId,
                    'product_id' => $line->productId,
                    'store_id' => $input->storeId,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'discount' => $line->discount,
                    'tax_category_id' => $engine['tax_category_id'],
                    'tax_rate_id' => $engine['tax_rate_id'],
                    'tax_rate_snapshot' => $engine['tax_rate_snapshot'],
                    'tax_amount' => $engine['tax_amount'],
                    'base_amount' => $engine['base_amount'],
                    'line_total' => $engine['line_total'],
                    'evidence_status' => $engine['evidence_status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $items[] = [$line, $itemId];
            }

            // 9. EL CONSUMO FIFO. El item ya existe, asi que la traza de
            //    lotes puede apuntar a el. Si no hay stock, la excepcion
            //    revierte T1 entera.
            foreach ($items as [$line, $itemId]) {
                $this->lotConsumer->consume($line->productId, $input->storeId, $itemId, $line->quantity);
            }

            // 10. LOS TOTALES.
            $this->connection->table('sale_totals')->insert([
                'sale_id' => $saleId,
                'sub_total' => $result->subtotal,
                'discount' => $result->discountTotal,
                'tax_total' => $result->taxTotal,
                'rounding_adjustment' => $result->roundingAdjustment,
                'grand_total' => $result->grandTotal,
                'evidence_status' => $this->headerEvidence($result->lines),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 11. EL ASIENTO CONTABLE. El tipo sale del catalogo
            //     (`ledger_entry_types`), no de un literal.
            $this->ledgerWriter->write(
                entryType: 'sale',
                storeId: $input->storeId,
                occurredAt: $input->issuedAt,
                businessDate: $input->businessDate,
                lines: $this->ledgerLines($input, $result),
                referenceType: 'sale',
                referenceId: $saleId,
                actorId: $input->actorId,
                description: 'Venta #'.$invoiceNo,
            );

            // 12. LOS PAGOS.
            foreach ($input->payments as $payment) {
                $this->connection->table('sale_payments')->insert([
                    'sale_id' => $saleId,
                    'payment_method_id' => $payment->paymentMethodId,
                    'amount' => $payment->amount,
                    'details' => $payment->details,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 13. EL RETORNO.
            return new CheckoutResult(
                saleId: $saleId,
                documentNumber: $invoiceNo,
                controlNumber: $controlNumber,
                grandTotal: $result->grandTotal,
                status: $status,
            );
        });
    }

    /**
     * El perfil fiscal de la serie, con su moneda y sus claves.
     *
     * El perfil se lee por la MISMA conexion del checkout: si se leyera por
     * otra, su contexto no veria la transaccion de T1.
     */
    private function profile(int $profileId): FiscalProfile
    {
        $model = (new FiscalProfile)->setConnection($this->connection->getName());

        $profile = $model->newQuery()
            ->with(['defaultCurrency', 'fields'])
            ->find($profileId);

        if ($profile === null) {
            throw new RuntimeException("El perfil fiscal {$profileId} no existe.");
        }

        return $profile;
    }

    /**
     * La categoria fiscal de la linea.
     *
     * La precedencia es: el override explicito de la linea, el override del
     * puente de la tienda y, si no hay, la categoria del producto. Sin
     * categoria no hay tratamento fiscal: la linea no se cobra en silencio.
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

    /**
     * El estado del pago: `paid` si los pagos cubren el total, `confirmed`
     * si no.
     */
    private function paymentStatus(array $payments, string $grandTotal): string
    {
        $cobrado = BigDecimal::zero()->toScale(4);
        $total = BigDecimal::of($grandTotal)->toScale(4, RoundingMode::HALF_UP);

        foreach ($payments as $payment) {
            $cobrado = $cobrado->plus(BigDecimal::of($payment->amount)->toScale(4, RoundingMode::HALF_UP));
        }

        return $cobrado->isEqualTo($total) ? 'paid' : 'confirmed';
    }

    /**
     * La evidencia congelada de la cabecera.
     *
     * La cabecera solo puede afirmar UNA evidencia si todas sus lineas la
     * comparten: un documento cuyas lineas discrepan no declara una
     * evidencia unica, y su cabecera queda en `null` en vez de afirmar una.
     *
     * @param  list<array<string, mixed>>  $engineLines
     */
    private function headerEvidence(array $engineLines): ?string
    {
        $estados = [];

        foreach ($engineLines as $line) {
            $estado = $line['evidence_status'];

            if ($estado !== null && ! in_array($estado, $estados, true)) {
                $estados[] = $estado;
            }
        }

        return count($estados) === 1 ? $estados[0] : null;
    }

    /**
     * El numero del documento: el prefijo lo declara la serie.
     */
    private function documentNumber(?string $prefix, int $number): string
    {
        $prefix = $prefix === null ? '' : trim($prefix);

        return $prefix === '' ? (string) $number : $prefix.$number;
    }

    /**
     * La cuenta contable de un rol.
     *
     * El mapa es DATO de la entrada: el dominio no inventa un plan de
     * cuentas. Si falta la cuenta que la operacion necesita, la operacion
     * falla con un error claro.
     */
    private function account(CheckoutInput $input, string $role): string
    {
        $code = $input->accounts[$role] ?? null;

        if ($code === null || trim($code) === '') {
            throw new RuntimeException(
                "La operacion necesita la cuenta contable del rol '{$role}' y la entrada no la trae."
            );
        }

        return trim($code);
    }

    /**
     * Las lineas del asiento de la venta.
     *
     * El asiento CUADRA: el debito del cobro es el total y el credito es el
     * ingreso mas el impuesto. Cuando el total redondeado no es exactamente
     * la base mas el impuesto, esa diferencia es DEL INGRESO: es parte de lo
     * cobrado, no un descuadre.
     *
     * Una linea sin importe NO es una linea contable, y el libro no admite
     * una linea a cero en los dos sentidos: las lineas vacias no se escriben.
     *
     * @return array<int, array{account: string, debit: string, credit: string}>
     */
    private function ledgerLines(CheckoutInput $input, TaxDocumentResult $result): array
    {
        $total = BigDecimal::of($result->grandTotal)->toScale(4, RoundingMode::HALF_UP);
        $ingreso = BigDecimal::of($result->subtotal)->toScale(4, RoundingMode::HALF_UP);
        $impuesto = BigDecimal::of($result->taxTotal)->toScale(4, RoundingMode::HALF_UP);

        $diferencia = $total->minus($ingreso)->minus($impuesto)->toScale(4, RoundingMode::HALF_UP);

        $lines = [
            [
                'account' => $this->account($input, $input->customerId === null ? 'cash' : 'receivable'),
                'debit' => (string) $total,
                'credit' => '0',
            ],
            [
                'account' => $this->account($input, 'revenue'),
                'debit' => '0',
                'credit' => (string) $ingreso,
            ],
            [
                'account' => $this->account($input, 'tax_payable'),
                'debit' => '0',
                'credit' => (string) $impuesto,
            ],
        ];

        if (! $diferencia->isZero()) {
            $lines[] = $diferencia->isNegative()
                ? [
                    'account' => $this->account($input, 'revenue'),
                    'debit' => (string) $diferencia->abs()->toScale(4, RoundingMode::HALF_UP),
                    'credit' => '0',
                ]
                : [
                    'account' => $this->account($input, 'revenue'),
                    'debit' => '0',
                    'credit' => (string) $diferencia,
                ];
        }

        return array_values(array_filter($lines, static function (array $line): bool {
            return ! BigDecimal::of($line['debit'])->isZero() || ! BigDecimal::of($line['credit'])->isZero();
        }));
    }
}
