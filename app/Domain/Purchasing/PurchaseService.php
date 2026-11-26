<?php

namespace App\Domain\Purchasing;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Inventory\StockInvariant;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La COMPRA: su fuente de verdad, la entrada del stock y su asiento.
 *
 * REGLA que fija esta clase: *una compra NO es un gasto: es EXISTENCIAS que
 * entran y DEUDA con el proveedor*. El asiento de la compra tiene UN debe
 * (las existencias) y UN haber (el pasivo del proveedor), y no tiene linea de
 * gasto: una compra que se cargara a un gasto seria mercaderia que se
 * consume el mismo dia que llega.
 *
 * Y EL STOCK ENTRA POR LOTES, no por un contador: cada linea de la compra abre
 * UN lote (el esquema lo exige: `inventory_lots.purchase_item_id` es 1:1 con
 * la linea de compra), y el cache `product_stores.quantity_in_stock` se
 * REFRESCA desde los lotes. El cache nunca se escribe a mano.
 *
 * Y LA DEUDA AL PROVEEDOR es un PASIVO: el haber de la compra va a la cuenta
 * que cumple el rol `liability` de la tienda. Y SI LA TIENDA NO TIENE
 * PASIVO, LA COMPRA NO SE BLOQUEA: el HECHO (la mercaderia entra y la deuda
 * se registra) se escribe igual, y lo que se avisa es la CONTABILIDAD. Es el
 * MISMO criterio de la F3-07 (la anulacion de una venta cobrada): el hecho no
 * se bloquea, y la contabilidad se avisa.
 *
 * Y LA COMPRA NO SE BORRA: se ANULA (que es un HECHO, con su motivo y su
 * responsable) o se DEVUELVE al proveedor. El P0 #9 del legacy (borrar una
 * compra revierte el stock de UN solo producto) no puede repetirse aqui: la
 * anulacion revierte el stock de TODAS sus lineas, y cada linea revierte SU
 * lote, no el lote mas antiguo.
 *
 * Y LO QUE ESTA UNIDAD NO HACE, Y POR QUE: *el repositorio no dice como se
 * reconoce el COSTO de las existencias*. El asiento de la venta
 * (`CheckoutService`) no tiene linea de costo de ventas, y el plan de
 * cuentas no tiene un rol para el inventario que la venta tenga que
 *creditar. Por eso la compra debita las existencias por lo que la tienda PAGA
 * por la mercaderia, y el hueco del costo queda declarado en el informe de la
 * unidad. Y el IMPUESTO de la compra tampoco se calcula aqui: el motor de
 * impuestos es el de los DOCUMENTOS (la compra todavia no tiene documento
 * fiscal) y el plan no tiene donde asentar el IVA descontable.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class PurchaseService
{
    /** La escala de las columnas: `numeric(25,4)`. */
    private const SCALE = 4;

    public function __construct(
        private Connection $connection,
        private SupplierService $suppliers,
        private StockInvariant $invariant,
    ) {}

    /**
     * REGISTRA una compra: su cabecera, sus lineas, sus lotes, su stock, su
     * deuda y su asiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El PROVEEDOR es OBLIGATORIO y necesita FICHA en la tienda: una
     *     compra sin proveedor no tiene donde asentar la deuda.
     *  2. Cada linea necesita CANTIDAD POSITIVA y un PRODUCTO CON FICHA en la
     *     tienda: el stock que entra necesita donde vivir.
     *  3. El IMPORTE TOTAL es POSITIVO: una compra de cero no es una compra.
     *  4. LA COMPRA Y SU ASIENTO se escriben en la MISMA transaccion: si el
     *     asiento falla, no queda compra.
     *
     * @param  list<array{product_id: int, quantity: string, unit_price: string, discount?: string}>  $lineas
     * @return PurchaseOutcome El resultado de la compra.
     *
     * @throws RuntimeException Cuando la compra no se puede registrar.
     */
    public function register(
        int $storeId,
        int $supplierId,
        ?string $invoiceNo,
        ?string $referenceNo,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        array $lineas,
        ?int $actorId = null,
    ): PurchaseOutcome {
        $preparadas = $this->preparaLasLineas($lineas);

        // El PROVEEDOR tiene ficha en la tienda: sin ella no hay donde dejar
        // la deuda.
        $this->suppliers->exigeFicha($supplierId, $storeId);

        return $this->connection->transaction(function () use (
            $storeId,
            $supplierId,
            $invoiceNo,
            $referenceNo,
            $businessDate,
            $occurredAt,
            $preparadas,
            $actorId
        ): PurchaseOutcome {
            // 1. LA CABECERA. El estado es `received`: la mercaderia entra con
            //    la compra, y sus lotes nacen aqui.
            $purchaseId = (int) $this->connection->table('purchases')->insertGetId([
                'store_id' => $storeId,
                'supplier_id' => $supplierId,
                'invoice_no' => $invoiceNo,
                'reference_no' => $referenceNo,
                'purchase_date' => $businessDate->format('Y-m-d'),
                'status' => 'received',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. LAS LINEAS y, con cada una, SU LOTE.
            //
            // El UNICO contador de salida del lote es `quantity_consumed`: el
            // esquema no tiene un contador de "salida por anulacion", y el
            // disponibles es ARITMETICO (`received - consumed + returned`).
            $total = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            $subTotal = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            $descuento = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
            $productos = [];

            foreach ($preparadas as $linea) {
                $productId = $linea['product_id'];

                $this->exigeFichaDelProducto($productId, $storeId);

                $base = BigDecimal::of($linea['unit_price'])
                    ->multipliedBy(BigDecimal::of($linea['quantity']))
                    ->minus(BigDecimal::of($linea['discount']))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($base->isNegative()) {
                    throw new RuntimeException(
                        "La linea del producto {$productId} tiene un descuento MAYOR que su importe: "
                        .'una compra no puede costar menos que cero.'
                    );
                }

                $itemId = (int) $this->connection->table('purchase_items')->insertGetId([
                    'purchase_id' => $purchaseId,
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'quantity' => $linea['quantity'],
                    'unit_price' => $linea['unit_price'],
                    'discount' => $linea['discount'],
                    // El impuesto de la compra NO se calcula en esta unidad: el
                    // motor es el de los documentos (y la compra todavia no
                    // tiene documento fiscal). Queda en cero, y el hueco
                    // esta declarado.
                    'tax_rate_snapshot' => null,
                    'tax_amount' => '0.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // EL LOTE: uno por linea (1:1, lo exige el esquema), con el
                // COSTE UNITARIO que la tienda pago por la mercaderia (la
                // base de la linea dividida entre las unidades).
                $unitCost = $base
                    ->dividedBy(BigDecimal::of($linea['quantity']), self::SCALE, RoundingMode::HALF_UP)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                $this->connection->table('inventory_lots')->insert([
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'purchase_item_id' => $itemId,
                    'quantity_received' => $linea['quantity'],
                    'quantity_consumed' => '0.0000',
                    'quantity_returned' => '0.0000',
                    'unit_cost' => (string) $unitCost,
                    'received_at' => $occurredAt->format('Y-m-d H:i:sP'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $subTotal = $subTotal->plus($base)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $total = $total->plus($base)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $descuento = $descuento->plus(BigDecimal::of($linea['discount']))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $productos[$productId] = true;
            }

            if ($total->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new RuntimeException(
                    'El importe de una compra tiene que ser mayor que cero: una compra de cero no es una compra.'
                );
            }

            // 3. LOS TOTALES. El impuesto es cero (vease la nota de arriba) y
            //    no hay descuento de documento: el descuento es de la linea.
            $this->connection->table('purchase_totals')->insert([
                'purchase_id' => $purchaseId,
                'sub_total' => (string) $subTotal,
                'discount' => (string) $descuento,
                'tax_total' => '0.0000',
                'rounding_adjustment' => '0.0000',
                'grand_total' => (string) $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. EL STOCK: el cache se REFRESCA desde los lotes, en la misma
            //    transaccion de la compra.
            foreach (array_keys($productos) as $productId) {
                $this->invariant->refresh((int) $productId, $storeId);
            }

            // 5. LA DEUDA del proveedor: el cache, desde los hechos.
            $this->suppliers->refreshCache($supplierId, $storeId);

            // 6. EL ASIENTO: el debe, a las existencias; el haber, al pasivo.
            //    Y si la tienda no tiene las dos cuentas, NO se escribe: el
            //    hecho ya esta, y lo que se avisa es la contabilidad.
            $asiento = $this->escribeElAsiento(
                storeId: $storeId,
                referenceType: 'purchase',
                referenceId: $purchaseId,
                total: (string) $total,
                businessDate: $businessDate,
                occurredAt: $occurredAt,
                actorId: $actorId,
                description: 'Compra '.(string) ($invoiceNo ?? $purchaseId),
            );

            return new PurchaseOutcome(
                purchaseId: $purchaseId,
                entryId: $asiento['entry_id'],
                entryWritten: $asiento['entry_id'] !== null,
                warning: $asiento['warning'],
                total: (string) $total,
            );
        });
    }

    /**
     * ANULA una compra: la MARCA, revierte el stock de TODAS sus lineas y
     * escribe el espejo de su asiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El MOTIVO es OBLIGATORIO: una compra anulada sin motivo es una
     *     compra que nadie sabe por que se toco.
     *  2. La compra NO se borra: se marca (`status`, `cancelled_at`,
     *     `cancelled_by` y `cancellation_reason`).
     *  3. Una compra YA ANULADA no se anula dos veces.
     *  4. EL STOCK se revierte de TODAS sus lineas y de SU lote: el P0 #9 del
     *     legacy (borrar una compra revierte el stock de un solo producto) no
     *     se repite. Y si la mercaderia YA SE VENDIO, la compra no se puede
     *     anular: hay que DEVOLVERLA al proveedor.
     *
     * @param  string  $reason  POR QUE se anula la compra. Obligatorio.
     *
     * @throws RuntimeException Cuando la compra no existe, ya esta anulada, no
     *                          tiene motivo, o su mercaderia ya se vendio.
     */
    public function cancel(int $purchaseId, string $reason, ?int $actorId = null): PurchaseOutcome
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw new RuntimeException(
                'Una compra se anula con un MOTIVO: una compra anulada sin motivo es una compra '
                .'que nadie sabe por que se toco.'
            );
        }

        $compra = $this->connection->table('purchases')->where('id', $purchaseId)->first();

        if ($compra === null) {
            throw new RuntimeException("La compra {$purchaseId} no existe.");
        }

        if ((string) $compra->status === 'cancelled') {
            throw new RuntimeException("La compra {$purchaseId} ya esta anulada: no se anula dos veces.");
        }

        $storeId = (int) $compra->store_id;

        return $this->connection->transaction(function () use ($compra, $purchaseId, $motivo, $actorId, $storeId): PurchaseOutcome {
            $lineas = $this->connection->table('purchase_items')
                ->where('purchase_id', $purchaseId)
                ->orderBy('id')
                ->get();

            // EL STOCK: el de TODAS las lineas, y de SU lote.
            foreach ($lineas as $linea) {
                $this->revierteElLoteDeLaLinea((int) $linea->id, (string) $linea->quantity, (int) $linea->product_id);
            }

            foreach ($lineas as $linea) {
                $this->invariant->refresh((int) $linea->product_id, $storeId);
            }

            // La compra se MARCA: no se borra. Y se marca ANTES de derivar la
            // deuda: una compra anulada no se debe.
            $this->connection->table('purchases')->where('id', $purchaseId)->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => $motivo,
                'updated_at' => now(),
            ]);

            // LA DEUDA del proveedor: una compra anulada no se debe.
            if ($compra->supplier_id !== null) {
                $this->suppliers->refreshCache((int) $compra->supplier_id, $storeId);
            }

            // EL ASIENTO: el ESPEJO del de la compra (el mismo criterio que
            // el gasto: la reversa es el espejo, exacto).
            $asiento = $this->escribeElEspejo(
                storeId: $storeId,
                referenceType: 'purchase_cancel',
                referenceId: $purchaseId,
                mirrorPurchaseId: $purchaseId,
                businessDate: new DateTimeImmutable((string) $compra->purchase_date),
                actorId: $actorId,
                description: 'Anulacion de la compra '.(string) ($compra->invoice_no ?? $purchaseId).': '.$motivo,
            );

            return new PurchaseOutcome(
                purchaseId: $purchaseId,
                entryId: $asiento['entry_id'],
                entryWritten: $asiento['entry_id'] !== null,
                warning: $asiento['warning'],
                total: $this->totalDeLaCompra($purchaseId),
            );
        });
    }

    /**
     * DEVUELVE mercaderia al PROVEEDOR: la mercaderia sale de la tienda y la
     * deuda baja.
     *
     * REGLAS que fija este metodo:
     *
     *  1. LA LINEA es la de la COMPRA: la devolucion sale de la linea que la
     *     trajo, y de SU lote (no del lote mas antiguo disponible: la
     *     mercaderia que se devuelve es la que se compro).
     *  2. LA MERCADERIA TIENE QUE ESTAR EN EL ALMACEN: si ya se vendio, no
     *     hay nada que devolver al proveedor.
     *  3. La DEUDA del proveedor BAJA por lo devuelto.
     *
     * @param  list<array{purchase_item_id: int, quantity: string}>  $lineas
     *
     * @throws RuntimeException Cuando la linea no es de la compra, o la
     *                          mercaderia ya no esta en el almacen.
     */
    public function returnToSupplier(
        int $purchaseId,
        array $lineas,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        ?string $referenceNo = null,
        ?int $actorId = null,
    ): PurchaseOutcome {
        $compra = $this->connection->table('purchases')->where('id', $purchaseId)->first();

        if ($compra === null) {
            throw new RuntimeException("La compra {$purchaseId} no existe.");
        }

        if ((string) $compra->status === 'cancelled') {
            throw new RuntimeException(
                "La compra {$purchaseId} esta anulada: no hay mercaderia que devolver."
            );
        }

        if ($lineas === []) {
            throw new RuntimeException(
                'Una devolucion al proveedor necesita al menos una linea: no hay nada que devolver.'
            );
        }

        $storeId = (int) $compra->store_id;

        return $this->connection->transaction(function () use (
            $compra,
            $purchaseId,
            $lineas,
            $businessDate,
            $occurredAt,
            $referenceNo,
            $actorId,
            $storeId
        ): PurchaseOutcome {
            $total = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);

            $returnId = (int) $this->connection->table('purchase_returns')->insertGetId([
                'purchase_id' => $purchaseId,
                'store_id' => $storeId,
                'reference_no' => $referenceNo,
                'return_date' => $businessDate->format('Y-m-d'),
                'total' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lineas as $linea) {
                $itemId = (int) $linea['purchase_item_id'];
                $cantidad = BigDecimal::of(PosAmount::declare($linea['quantity'], 'cantidad devuelta'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($cantidad->isLessThanOrEqualTo(BigDecimal::zero())) {
                    throw new RuntimeException(
                        'La cantidad devuelta tiene que ser mayor que cero: una devolucion de nada no es una devolucion.'
                    );
                }

                $item = $this->connection->table('purchase_items')
                    ->where('id', $itemId)
                    ->where('purchase_id', $purchaseId)
                    ->first();

                if ($item === null) {
                    throw new RuntimeException(
                        "La linea {$itemId} no es de la compra {$purchaseId}: no se puede devolver mercaderia ajena."
                    );
                }

                $lote = $this->connection->table('inventory_lots')
                    ->where('purchase_item_id', $itemId)
                    ->first();

                if ($lote === null) {
                    throw new RuntimeException(
                        "La linea {$itemId} no tiene lote: no hay de donde sacar la mercaderia que se devuelve."
                    );
                }

                $disponible = BigDecimal::of((string) $lote->available)->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($disponible->isLessThan($cantidad)) {
                    throw new RuntimeException(
                        "La mercaderia de la linea {$itemId} ya no esta en el almacen (quedan {$disponible}): "
                        .'no hay nada que devolver al proveedor.'
                    );
                }

                // EL LOTE EXACTO: la mercaderia que sale es la que entro con
                // esta linea, no la mas antigua disponible.
                $this->connection->table('inventory_lots')
                    ->where('id', (int) $lote->id)
                    ->update([
                        'quantity_consumed' => $this->connection->raw(
                            'quantity_consumed + '.(string) $cantidad
                        ),
                        'updated_at' => now(),
                    ]);

                $importe = BigDecimal::of((string) $lote->unit_cost)
                    ->multipliedBy($cantidad)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                $this->connection->table('purchase_return_items')->insert([
                    'purchase_return_id' => $returnId,
                    'purchase_item_id' => $itemId,
                    'product_id' => (int) $item->product_id,
                    'quantity' => (string) $cantidad,
                    'amount' => (string) $importe,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $total = $total->plus($importe)->toScale(self::SCALE, RoundingMode::HALF_UP);

                $this->invariant->refresh((int) $item->product_id, $storeId);
            }

            $this->connection->table('purchase_returns')->where('id', $returnId)->update([
                'total' => (string) $total,
                'updated_at' => now(),
            ]);

            // LA DEUDA del proveedor: lo devuelto se le descuenta.
            if ($compra->supplier_id !== null) {
                $this->suppliers->refreshCache((int) $compra->supplier_id, $storeId);
            }

            // EL ASIENTO: el debe, al pasivo del proveedor (la deuda baja); el
            //    haber, a las existencias que salen. Y es el de LO DEVUELTO,
            //    no el espejo de la compra entera: una devolucion es una
            //    parte, y el espejo es de la anulacion.
            $asiento = $this->escribeElAsientoDeLaDevolucion(
                storeId: $storeId,
                returnId: $returnId,
                total: (string) $total,
                businessDate: $businessDate,
                occurredAt: $occurredAt,
                actorId: $actorId,
                description: 'Devolucion al proveedor de la compra '.(string) ($compra->invoice_no ?? $purchaseId),
            );

            return new PurchaseOutcome(
                purchaseId: $purchaseId,
                returnId: $returnId,
                entryId: $asiento['entry_id'],
                entryWritten: $asiento['entry_id'] !== null,
                warning: $asiento['warning'],
                total: (string) $total,
            );
        });
    }

    /**
     * Las LINEAS de la compra, preparadas y validadas.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return list<array{product_id: int, quantity: string, unit_price: string, discount: string}>
     *
     * @throws RuntimeException Cuando no hay lineas o una linea no es valida.
     */
    private function preparaLasLineas(array $lineas): array
    {
        if ($lineas === []) {
            throw new RuntimeException(
                'Una compra necesita al menos una linea: una compra sin mercaderia no es una compra.'
            );
        }

        $preparadas = [];

        foreach ($lineas as $linea) {
            $productId = (int) ($linea['product_id'] ?? 0);

            if ($productId <= 0) {
                throw new RuntimeException('Toda linea de compra necesita un PRODUCTO.');
            }

            $cantidad = BigDecimal::of(PosAmount::declare($linea['quantity'] ?? '0', 'cantidad de la compra'))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($cantidad->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new RuntimeException(
                    "La linea del producto {$productId} pide una cantidad no positiva: no hay mercaderia que comprar."
                );
            }

            $precio = BigDecimal::of(PosAmount::declare($linea['unit_price'] ?? '0', 'precio de la compra'))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($precio->isNegative()) {
                throw new RuntimeException(
                    "La linea del producto {$productId} tiene un precio NEGATIVO: no se compra por menos de cero."
                );
            }

            $descuento = BigDecimal::of(PosAmount::declare($linea['discount'] ?? '0', 'descuento de la compra'))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($descuento->isNegative()) {
                throw new RuntimeException(
                    "La linea del producto {$productId} tiene un descuento NEGATIVO: un descuento no suma."
                );
            }

            $preparadas[] = [
                'product_id' => $productId,
                'quantity' => (string) $cantidad,
                'unit_price' => (string) $precio,
                'discount' => (string) $descuento,
            ];
        }

        return $preparadas;
    }

    /**
     * El producto TIENE ficha en la tienda: el stock que entra necesita donde
     * vivir.
     *
     * @throws RuntimeException Cuando el producto no tiene ficha.
     */
    private function exigeFichaDelProducto(int $productId, int $storeId): void
    {
        $existe = $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->exists();

        if ($existe) {
            return;
        }

        throw new RuntimeException(
            "El producto {$productId} no tiene ficha en la tienda {$storeId}: "
            .'su stock no tendria donde vivir.'
        );
    }

    /**
     * Revierte el stock de UNA linea de la compra: SU lote.
     *
     * @throws RuntimeException Cuando la mercaderia ya se vendio.
     */
    private function revierteElLoteDeLaLinea(int $itemId, string $cantidad, int $productId): void
    {
        $lote = $this->connection->table('inventory_lots')
            ->where('purchase_item_id', $itemId)
            ->first();

        if ($lote === null) {
            throw new RuntimeException(
                "La linea {$itemId} no tiene lote: no hay stock que revertir."
            );
        }

        $disponible = BigDecimal::of((string) $lote->available)->toScale(self::SCALE, RoundingMode::HALF_UP);
        $recibida = BigDecimal::of((string) $cantidad)->toScale(self::SCALE, RoundingMode::HALF_UP);

        if ($disponible->isLessThan($recibida)) {
            throw new RuntimeException(
                "La mercaderia de la linea {$itemId} ya se vendio (quedan {$disponible} de {$recibida}): "
                .'una compra con mercaderia vendida no se anula, se DEVUELVE al proveedor.'
            );
        }

        // El UNICO contador de salida del lote es `quantity_consumed`: el
        // disponible es aritmetico (`received - consumed + returned`).
        $this->connection->table('inventory_lots')
            ->where('id', (int) $lote->id)
            ->update([
                'quantity_consumed' => $this->connection->raw(
                    'quantity_consumed + '.(string) $recibida
                ),
                'updated_at' => now(),
            ]);
    }

    /**
     * EL ASIENTO de la compra: el debe, a las existencias; el haber, al
     * pasivo del proveedor.
     *
     * Y SI LA TIENDA NO TIENE LAS CUENTAS, EL ASIENTO NO SE ESCRIBE: el
     * hecho ya esta escrito, y lo que se avisa es la contabilidad. Es el
     * criterio de la F3-07.
     *
     * @return array{entry_id: int|null, warning: string|null}
     */
    private function escribeElAsiento(
        int $storeId,
        string $referenceType,
        int $referenceId,
        string $total,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        string $description,
    ): array {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        $existencias = trim((string) ($roles[AccountRole::Inventory->value] ?? ''));
        $pasivo = trim((string) ($roles[AccountRole::Liability->value] ?? ''));

        if ($existencias === '' || $pasivo === '') {
            return [
                'entry_id' => null,
                'warning' => $this->mensajeSinCuentras($storeId, $existencias === '', $pasivo === ''),
            ];
        }

        $entryId = (new LedgerWriter($this->connection))->write(
            entryType: 'purchase',
            storeId: $storeId,
            occurredAt: $occurredAt,
            businessDate: $businessDate,
            lines: [
                ['account' => $existencias, 'debit' => $total, 'credit' => '0'],
                ['account' => $pasivo, 'debit' => '0', 'credit' => $total],
            ],
            referenceType: $referenceType,
            referenceId: $referenceId,
            actorId: $actorId,
            description: $description,
        );

        return ['entry_id' => $entryId, 'warning' => null];
    }

    /**
     * EL ASIENTO de la devolucion al proveedor: el debe, al pasivo del
     * proveedor (la deuda baja); el haber, a las existencias que salen.
     *
     * Y SI LA TIENDA NO TIENE LAS CUENTAS, EL ASIENTO NO SE ESCRIBE: el
     * hecho ya esta (la mercaderia salio y la deuda bajo), y lo que se avisa
     * es la contabilidad. Es el criterio de la F3-07.
     *
     * @return array{entry_id: int|null, warning: string|null}
     */
    private function escribeElAsientoDeLaDevolucion(
        int $storeId,
        int $returnId,
        string $total,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        string $description,
    ): array {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        $existencias = trim((string) ($roles[AccountRole::Inventory->value] ?? ''));
        $pasivo = trim((string) ($roles[AccountRole::Liability->value] ?? ''));

        if ($existencias === '' || $pasivo === '') {
            return [
                'entry_id' => null,
                'warning' => $this->mensajeSinCuentras($storeId, $existencias === '', $pasivo === ''),
            ];
        }

        $entryId = (new LedgerWriter($this->connection))->write(
            entryType: 'refund',
            storeId: $storeId,
            occurredAt: $occurredAt,
            businessDate: $businessDate,
            lines: [
                ['account' => $pasivo, 'debit' => $total, 'credit' => '0'],
                ['account' => $existencias, 'debit' => '0', 'credit' => $total],
            ],
            referenceType: 'purchase_return',
            referenceId: $returnId,
            actorId: $actorId,
            description: $description,
        );

        return ['entry_id' => $entryId, 'warning' => null];
    }

    /**
     * EL ESPEJO del asiento de la compra: las mismas lineas con el debe y el
     * haber cambiados.
     *
     * REGLA que fija este metodo: *la reversa de un movimiento es su espejo*.
     * Es exacto aunque despues el plan de cuentas cambie, y por eso no
     * recalcula nada: lee lo que se escribio.
     *
     * @return array{entry_id: int|null, warning: string|null}
     */
    private function escribeElEspejo(
        int $storeId,
        string $referenceType,
        int $referenceId,
        int $mirrorPurchaseId,
        DateTimeImmutable $businessDate,
        ?int $actorId,
        string $description,
    ): array {
        $asiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'purchase')
            ->where('reference_id', $mirrorPurchaseId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first(['id']);

        if ($asiento === null) {
            // Sin asiento no hay espejo que escribir: y eso NO es lo mismo que
            // no tener pasivo. El hecho ya esta.
            return [
                'entry_id' => null,
                'warning' => 'La compra no tiene asiento en el libro: no hay espejo que escribir.',
            ];
        }

        $lineas = $this->connection->table('ledger_lines')
            ->where('ledger_entry_id', (int) $asiento->id)
            ->orderBy('id')
            ->get(['account_code', 'debit', 'credit']);

        if ($lineas->isEmpty()) {
            return [
                'entry_id' => null,
                'warning' => 'La compra no tiene lineas contables: no hay espejo que escribir.',
            ];
        }

        $espejo = [];

        foreach ($lineas as $linea) {
            $espejo[] = [
                'account' => (string) $linea->account_code,
                'debit' => (string) $linea->credit,
                'credit' => (string) $linea->debit,
            ];
        }

        $entryId = (new LedgerWriter($this->connection))->write(
            entryType: 'adjustment',
            storeId: $storeId,
            occurredAt: new DateTimeImmutable('now'),
            businessDate: $businessDate,
            lines: $espejo,
            referenceType: $referenceType,
            referenceId: $referenceId,
            actorId: $actorId,
            description: $description,
        );

        return ['entry_id' => $entryId, 'warning' => null];
    }

    /**
     * El MENSAJE de la compra que no se pudo asentar: que cuenta falta y que
     * hay que hacer.
     */
    private function mensajeSinCuentras(int $storeId, bool $sinExistencias, bool $sinPasivo): string
    {
        $faltan = [];

        if ($sinExistencias) {
            $faltan[] = "'inventory' (las existencias, donde se asienta el stock)";
        }

        if ($sinPasivo) {
            $faltan[] = "'liability' (el pasivo, donde se asienta la deuda con el proveedor)";
        }

        return "La tienda {$storeId} no tiene ".implode(' ni ', $faltan).' en su plan de cuentas: '
            .'la compra se ha registrado y el stock ha entrado, pero su asiento NO se ha escrito. '
            .'Asigna el rol a una cuenta de su tipo en el plan de cuentas y vuelve a intentarlo.';
    }

    /**
     * El TOTAL de la compra, como texto decimal.
     */
    private function totalDeLaCompra(int $purchaseId): string
    {
        $total = $this->connection->table('purchase_totals')
            ->where('purchase_id', $purchaseId)
            ->value('grand_total');

        return $total === null ? '0.0000' : (string) $total;
    }
}
