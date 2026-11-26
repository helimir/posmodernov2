<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Inventory\LotReturner;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La DEVOLUCION DE MERCADERIA a un cliente: la mercaderia vuelve y el libro
 * del cliente queda compensado.
 *
 * Y ESTA NO ES LA ANULACION: la anulacion (F3-07) es FISCAL Y CONTABLE, y la
 * mercaderia NO vuelve al almacen. Aqui la mercaderia SI vuelve, y por eso
 * el stock se repone.
 *
 * Y EL LOTE QUE SE REPONE ES EL EXACTO: el que salio de la linea de venta
 * que se devuelve. NO se repone el lote mas antiguo disponible ni se crea un
 * lote nuevo: eso lo hace `LotReturner`, y aqui no se reimplementa.
 *
 * Y EL REINTEGRO lo registra `CustomerBalanceService::registerReturn()`: es
 * el reintegro, con su TIPO PROPIO (`return`), y no un cobro. El saldo del
 * cliente baja, y si queda A FAVOR, la tienda le DEBE: ese credito es un
 * pasivo, y sin pasivo el asiento NO se escribe (el hecho no se bloquea, y la
 * contabilidad se avisa: el criterio de la F3-07).
 *
 * Y EL VALOR DEVUELTO cancela primero la DEUDA del cliente y el resto es su
 * CREDITO: la parte que cancela la deuda va a la cuenta por cobrar (la deuda
 * deja de existir) y la parte que la excede va al pasivo (la tienda le debe
 * al cliente). Y SIN CLIENTE, el dinero vuelve a la caja: no hay a quien
 * dejarlo a credito.
 *
 * Y LO QUE ESTA UNIDAD NO HACE, Y POR QUE: *el repositorio no dice como se
 * reconoce el COSTO de las existencias*. El asiento de la venta no tiene linea
 * de costo de ventas, asi que aqui la mercaderia que vuelve repone el STOCK
 * (los lotes y su cache) pero no Credita las existencias: el hueco del costo
 * queda declarado en el informe de la unidad.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class SaleReturnService
{
    /** La escala de las columnas: `numeric(25,4)`. */
    private const SCALE = 4;

    public function __construct(
        private Connection $connection,
        private LotReturner $lotReturner,
        private CustomerBalanceService $balances,
    ) {}

    /**
     * REGISTRA una devolucion de mercaderia a un cliente.
     *
     * REGLAS que fija este metodo:
     *
     *  1. La devolucion es de UNA VENTA de la tienda, y la venta NO esta
     *     anulada: una venta anulada ya esta sin efecto.
     *  2. Cada linea es de ESA VENTA, y la cantidad devuelta NO puede ser mayor
     *     que la que salio: no se repone mercaderia que no salio.
     *  3. La MERCADERIA VUELVE AL LOTE EXACTO del que salio (lo repone
     *     `LotReturner`), y el reintegro lo registra el libro del cliente.
     *  4. La devolucion y su asiento se escriben en la MISMA transaccion.
     *
     * @param  list<array{sale_item_id: int, quantity: string}>  $lineas
     *
     * @throws RuntimeException Cuando la devolucion no se puede registrar.
     */
    public function register(
        int $storeId,
        int $saleId,
        array $lineas,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        ?string $referenceNo = null,
        ?int $actorId = null,
    ): SaleReturnOutcome {
        if ($lineas === []) {
            throw new RuntimeException(
                'Una devolucion necesita al menos una linea: no hay mercaderia que devolver.'
            );
        }

        $venta = $this->connection->table('sales')->where('id', $saleId)->first();

        if ($venta === null) {
            throw new RuntimeException("La venta {$saleId} no existe.");
        }

        if ((int) $venta->store_id !== $storeId) {
            throw new RuntimeException("La venta {$saleId} no es de la tienda {$storeId}.");
        }

        if ((string) $venta->status === 'cancelled') {
            throw new RuntimeException(
                "La venta {$saleId} esta anulada: no hay mercaderia que devolver."
            );
        }

        return $this->connection->transaction(function () use (
            $storeId,
            $venta,
            $saleId,
            $lineas,
            $businessDate,
            $occurredAt,
            $referenceNo,
            $actorId
        ): SaleReturnOutcome {
            $total = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            $ingresos = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            $impuestos = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);

            $returnId = (int) $this->connection->table('sale_returns')->insertGetId([
                'sale_id' => $saleId,
                'store_id' => $storeId,
                'reference_no' => $referenceNo,
                'return_date' => $businessDate->format('Y-m-d'),
                'total' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lineas as $linea) {
                $itemId = (int) $linea['sale_item_id'];
                $cantidad = BigDecimal::of(PosAmount::declare($linea['quantity'] ?? '0', 'cantidad devuelta'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($cantidad->isLessThanOrEqualTo(BigDecimal::zero())) {
                    throw new RuntimeException(
                        'La cantidad devuelta tiene que ser mayor que cero: una devolucion de nada no es una devolucion.'
                    );
                }

                $item = $this->connection->table('sale_items')
                    ->where('id', $itemId)
                    ->where('sale_id', $saleId)
                    ->first();

                if ($item === null) {
                    throw new RuntimeException(
                        "La linea {$itemId} no es de la venta {$saleId}: no se puede devolver mercaderia ajena."
                    );
                }

                $vendida = BigDecimal::of((string) $item->quantity)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $devueltaAntes = $this->devueltoDeLaLinea($itemId);
                $disponible = $vendida->minus($devueltaAntes)->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($cantidad->isGreaterThan($disponible)) {
                    throw new RuntimeException(
                        "De la linea {$itemId} solo se pueden devolver {$disponible} (de {$vendida}): "
                        .'no se repone mercaderia que no salio.'
                    );
                }

                // EL LOTE EXACTO: `LotReturner` repone el lote del que salio
                // la linea, y el cache del stock lo refresca en la misma
                // transaccion.
                $this->lotReturner->returnToLot($itemId, (string) $cantidad);

                // El VALOR devuelto es la parte de la linea que se devuelve.
                $proporcion = $vendida->isZero()
                    ? BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP)
                    : $cantidad->dividedBy($vendida, self::SCALE, RoundingMode::HALF_UP);

                $base = BigDecimal::of((string) $item->base_amount)
                    ->multipliedBy($proporcion)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $impuesto = BigDecimal::of((string) $item->tax_amount)
                    ->multipliedBy($proporcion)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $importe = $base->plus($impuesto)->toScale(self::SCALE, RoundingMode::HALF_UP);

                $this->connection->table('sale_return_items')->insert([
                    'sale_return_id' => $returnId,
                    'sale_item_id' => $itemId,
                    'product_id' => (int) $item->product_id,
                    'quantity' => (string) $cantidad,
                    'amount' => (string) $importe,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $total = $total->plus($importe)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $ingresos = $ingresos->plus($base)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $impuestos = $impuestos->plus($impuesto)->toScale(self::SCALE, RoundingMode::HALF_UP);
            }

            $this->connection->table('sale_returns')->where('id', $returnId)->update([
                'total' => (string) $total,
                'updated_at' => now(),
            ]);

            // EL LIBRO DEL CLIENTE: el reintegro es de SU propio tipo, y no un
            // cobro. Y el saldo de ANTES dice cuanto era la deuda que la
            // devolucion cancela.
            $customerId = $venta->customer_id === null ? null : (int) $venta->customer_id;
            $deudaAntes = '0.0000';

            if ($customerId !== null) {
                $deudaAntes = $this->balances->balance($customerId, $storeId);

                $this->balances->registerReturn(
                    customerId: $customerId,
                    storeId: $storeId,
                    amount: (string) $total,
                    referenceNo: (string) ($referenceNo ?? $venta->invoice_no ?? ''),
                    entryDate: $businessDate,
                    saleId: $saleId,
                );
            }

            // EL VALOR DEVUELTO: primero cancela la deuda del cliente, y el
            // resto es su CREDITO (un pasivo de la tienda).
            $deuda = BigDecimal::of($deudaAntes)->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($deuda->isNegative()) {
                $deuda = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            }

            $deudaCancelada = $deuda->isGreaterThan($total) ? $total : $deuda;
            $credito = $total->minus($deudaCancelada)->toScale(self::SCALE, RoundingMode::HALF_UP);

            $asiento = $this->escribeElAsiento(
                storeId: $storeId,
                returnId: $returnId,
                saleId: $saleId,
                ingresos: (string) $ingresos,
                impuestos: (string) $impuestos,
                total: (string) $total,
                deudaCancelada: (string) $deudaCancelada,
                credito: (string) $credito,
                hayCliente: $customerId !== null,
                businessDate: $businessDate,
                occurredAt: $occurredAt,
                actorId: $actorId,
            );

            $saldo = $customerId === null
                ? '0.0000'
                : $this->balances->balance($customerId, $storeId);

            return new SaleReturnOutcome(
                returnId: $returnId,
                saleId: $saleId,
                total: (string) $total,
                balance: $saldo,
                credit: (string) $credito,
                entryId: $asiento['entry_id'],
                entryWritten: $asiento['entry_id'] !== null,
                warning: $asiento['warning'],
            );
        });
    }

    /**
     * EL ASIENTO de la devolucion: el ingreso y el impuesto se revierten, y
     * el valor devuelto cancela la deuda o se queda a favor del cliente.
     *
     * @return array{entry_id: int|null, warning: string|null}
     */
    private function escribeElAsiento(
        int $storeId,
        int $returnId,
        int $saleId,
        string $ingresos,
        string $impuestos,
        string $total,
        string $deudaCancelada,
        string $credito,
        bool $hayCliente,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): array {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        $cuentaIngresos = trim((string) ($roles[AccountRole::Revenue->value] ?? ''));
        $cuentaImpuesto = trim((string) ($roles[AccountRole::TaxPayable->value] ?? ''));

        if ($cuentaIngresos === '' || $cuentaImpuesto === '') {
            return [
                'entry_id' => null,
                'warning' => "La tienda {$storeId} no tiene cuenta de ingresos ni de impuesto por pagar: "
                    .'la devolucion se ha registrado y la mercaderia ha vuelto, pero su asiento NO se ha escrito.',
            ];
        }

        $lineas = [];

        if (! BigDecimal::of($ingresos)->isZero()) {
            $lineas[] = [
                'account' => $cuentaIngresos,
                'debit' => $ingresos,
                'credit' => '0',
            ];
        }

        if (! BigDecimal::of($impuestos)->isZero()) {
            $lineas[] = [
                'account' => $cuentaImpuesto,
                'debit' => $impuestos,
                'credit' => '0',
            ];
        }

        // EL VALOR DEVUELTO: sin cliente, el dinero vuelve a la CAJA; con
        // cliente, cancela su DEUDA y el resto es su CREDITO (un pasivo).
        if (! $hayCliente) {
            $cuentaCaja = trim((string) ($roles[AccountRole::Cash->value] ?? ''));

            if ($cuentaCaja === '') {
                return [
                    'entry_id' => null,
                    'warning' => "La tienda {$storeId} no tiene cuenta de efectivo: "
                        .'la devolucion se ha registrado, pero su asiento NO se ha escrito.',
                ];
            }

            $lineas[] = [
                'account' => $cuentaCaja,
                'debit' => '0',
                'credit' => $total,
            ];
        } else {
            if (! BigDecimal::of($deudaCancelada)->isZero()) {
                $cuentaCartera = trim((string) ($roles[AccountRole::Receivable->value] ?? ''));

                if ($cuentaCartera === '') {
                    return [
                        'entry_id' => null,
                        'warning' => "La tienda {$storeId} no tiene cuenta de cartera: "
                            .'la devolucion se ha registrado, pero su asiento NO se ha escrito.',
                    ];
                }

                $lineas[] = [
                    'account' => $cuentaCartera,
                    'debit' => '0',
                    'credit' => $deudaCancelada,
                ];
            }

            if (! BigDecimal::of($credito)->isZero()) {
                $cuentaPasivo = trim((string) ($roles[AccountRole::Liability->value] ?? ''));

                if ($cuentaPasivo === '') {
                    // EL CREDITO A FAVOR DEL CLIENTE es un pasivo, y sin el
                    // el asiento NO se escribe. El HECHO (la mercaderia ha
                    // vuelto) no se bloquea: se avisa, y se sigue.
                    return [
                        'entry_id' => null,
                        'warning' => "La tienda {$storeId} no tiene cuenta de pasivo y el cliente queda "
                            .'con un credito a favor: la devolucion se ha registrado y la mercaderia ha vuelto, '
                            .'pero su asiento NO se ha escrito. Asigna el rol \'liability\' a una cuenta de '
                            .'tipo pasivo en el plan de cuentas.',
                    ];
                }

                $lineas[] = [
                    'account' => $cuentaPasivo,
                    'debit' => '0',
                    'credit' => $credito,
                ];
            }
        }

        if ($lineas === []) {
            return [
                'entry_id' => null,
                'warning' => 'La devolucion no tiene importe: no hay asiento que escribir.',
            ];
        }

        $entryId = (new LedgerWriter($this->connection))->write(
            entryType: 'refund',
            storeId: $storeId,
            occurredAt: $occurredAt,
            businessDate: $businessDate,
            lines: $lineas,
            referenceType: 'sale_return',
            referenceId: $returnId,
            actorId: $actorId,
            description: 'Devolucion de la venta #'.$saleId,
        );

        return ['entry_id' => $entryId, 'warning' => null];
    }

    /**
     * Lo que ya se ha devuelto de una linea de venta.
     *
     * REGLA que fija este metodo: *no se repone mas de lo que salio*: la
     * cantidad devuelta se compara con la cantidad VENDIDA menos la ya
     * devuelta.
     */
    private function devueltoDeLaLinea(int $saleItemId): BigDecimal
    {
        $total = $this->connection->table('sale_return_items')
            ->where('sale_item_id', $saleItemId)
            ->sum('quantity');

        return BigDecimal::of($total ?? '0')->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
