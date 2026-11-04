<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use App\Domain\Sales\SalePaymentStatus;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * La emision fiscal (T2) y su reintento.
 *
 * Este metodo es a la vez la emision y el reintento: lo UNICO que cambia
 * entre los dos es si la venta ya tiene un documento en `draft` de un
 * intento anterior. No hay dos caminos, y por eso no pueden divergir.
 *
 * El flujo respeta el SECUENCING de T2 (design §B.1, decision C5):
 *
 *  1. La LECTURA (venta, totales, items, cliente y reserva) y la
 *     PREPARACION del documento ocurren FUERA de toda transaccion: son
 *     lecturas y no deben abrir un bloqueo de fila.
 *  2. La llamada al actor intermedio ocurre FUERA de la transaccion: es
 *     I/O externo, y mantener un bloqueo de fila durante un HTTP puede
 *     agotar el `lock_timeout` de la caja.
 *  3. La escritura es una unidad atomica POR FASE: el borrador y su
 *     enlace con la venta van juntos; el documento, el evento y la
 *     asignacion de control van juntos (los persiste el
 *     `FiscalIssuanceService`).
 *
 * El REINTENTO reutiliza la reserva: una venta sin reserva no se puede
 * emitir, y jamas se reserva un numero nuevo para suplirla. El numero
 * reservado puede haberse publicado en la autoridad, y devolverlo al
 * correlativo entregaria DOS veces el mismo numero.
 *
 * `fiscal_failed` PISA el estado de pago, porque `sales.status` es un unico
 * enum. Por eso un reintento exitoso RECALCULA el estado de pago desde
 * `sale_payments` en vez de restaurarlo: el estado anterior ya no existe.
 */
final readonly class FiscalIssuanceCoordinator
{
    public function __construct(
        private Connection $connection,
        private FiscalIssuanceService $issuance,
        private SalePaymentStatus $paymentStatus,
    ) {}

    /**
     * Emite el comprobante fiscal de la venta y devuelve su identificador.
     *
     * @param  int  $saleId  La venta que se emite.
     * @param  int|null  $actorId  Quien emite; queda en la auditoria.
     *
     * @throws RuntimeException Cuando la venta no existe o no tiene reserva.
     */
    public function issue(int $saleId, ?int $actorId = null): int
    {
        // 1. LA LECTURA, FUERA de toda transaccion.
        $sale = $this->sale($saleId);
        $reservation = $this->reservation($saleId);
        $series = $this->series($reservation);
        $profile = $this->profile($series);
        $currencyCode = $this->currencyCode($profile);
        $totals = $this->totals($saleId);
        $items = $this->items($saleId);
        $customerId = $this->customerId($sale);

        // 2. EL DOCUMENTO: se REUTILIZA el borrador de un intento anterior, o
        //    se crea uno nuevo. El borrador y su enlace con la venta van en
        //    la MISMA transaccion: o existen los dos, o ninguno.
        $documentId = $this->document($saleId, $sale, $reservation, $series, $profile, $totals, $customerId);

        // 3. EL DESGLOSE DE IMPUESTOS del documento. Es IDEMPOTENTE: un
        //    intento anterior ya lo pudo escribir, y un documento no lleva
        //    dos veces el mismo desglose.
        $this->taxes($documentId, $items, $totals);

        // 4. SI EL DOCUMENTO YA ESTA EMITIDO, no se vuelve a llamar al
        //    proveedor. Una doble emision ante la autoridad NO se puede
        //    deshacer: devolver el numero no deshace un comprobante ya
        //    publicado. Aqui solo se reconcilia el estado de la venta.
        if ($this->documentStatus($documentId) === 'issued') {
            $this->settle($saleId);

            return $documentId;
        }

        // 5. EL BORRADOR que se entrega al actor intermedio.
        $draft = $this->draft($sale, $series, $reservation, $currencyCode, $items);

        // 6. LA LLAMADA AL PROVEEDOR, FUERA de la transaccion.
        try {
            $this->issuance->issue(
                $documentId,
                $draft,
                (bool) $profile->requires_control_number,
                $actorId
            );
        } catch (Throwable $fallo) {
            // 7. El proveedor fallo. El `FiscalIssuanceService` no ha
            //    escrito nada, asi que solo queda marcar la venta: el
            //    numero NO se libera y el documento sigue en `draft`.
            $this->markFailed($saleId);

            // La excepcion ORIGINAL, sin envolver: el llamante tiene que
            // saber que la emision fallo.
            throw $fallo;
        }

        // 8. El exito. El estado de pago se RECALCULA desde los pagos,
        //    porque `fiscal_failed` habia pisado el estado anterior.
        $this->settle($saleId);

        return $documentId;
    }

    /**
     * El estado del documento fiscal.
     */
    private function documentStatus(int $documentId): string
    {
        $status = $this->connection->table('fiscal_documents')
            ->where('id', $documentId)
            ->value('status');

        if ($status === null) {
            throw new RuntimeException("El documento fiscal {$documentId} no existe.");
        }

        return (string) $status;
    }

    /**
     * La venta vuelve al estado que le corresponde por su pago.
     *
     * `fiscal_failed` PISA el estado de pago, porque `sales.status` es un
     * unico enum: el estado anterior ya no existe, asi que se RECALCULA desde
     * los pagos y no se restaura.
     */
    private function settle(int $saleId): void
    {
        $status = $this->paymentStatus->forSale($saleId);

        $this->connection->transaction(function () use ($saleId, $status): void {
            $this->connection->table('sales')->where('id', $saleId)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * La venta que se emite.
     */
    private function sale(int $saleId): object
    {
        $sale = $this->connection->table('sales')->where('id', $saleId)->first();

        if ($sale === null) {
            throw new RuntimeException("La venta {$saleId} no existe.");
        }

        return $sale;
    }

    /**
     * La reserva del numero de documento de la venta.
     *
     * Se busca por el ENLACE (`reference_type` + `reference_id`), no por la
     * serie: el reintento tiene que encontrar la reserva que YA tiene, y no
     * la ultima de la serie.
     */
    private function reservation(int $saleId): object
    {
        $reservation = $this->connection->table('fiscal_document_reservations')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('series_role', 'document')
            ->first();

        if ($reservation === null) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene reserva de documento: no se puede emitir su comprobante."
            );
        }

        return $reservation;
    }

    /**
     * La serie de la reserva: de ella salen el perfil y el prefijo.
     */
    private function series(object $reservation): object
    {
        $series = $this->connection->table('fiscal_series')
            ->where('id', $reservation->series_id)
            ->first();

        if ($series === null) {
            throw new RuntimeException(
                "La serie {$reservation->series_id} de la reserva no existe."
            );
        }

        return $series;
    }

    /**
     * El perfil fiscal de la serie.
     */
    private function profile(object $series): object
    {
        $profile = $this->connection->table('fiscal_profiles')
            ->where('id', $series->fiscal_profile_id)
            ->first();

        if ($profile === null) {
            throw new RuntimeException(
                "El perfil fiscal {$series->fiscal_profile_id} de la serie no existe."
            );
        }

        return $profile;
    }

    /**
     * El codigo de la moneda del perfil.
     */
    private function currencyCode(object $profile): string
    {
        if ($profile->default_currency_id === null) {
            throw new RuntimeException(
                "El perfil fiscal {$profile->id} no tiene moneda: no se puede emitir su comprobante."
            );
        }

        $code = $this->connection->table('currencies')
            ->where('id', $profile->default_currency_id)
            ->value('code');

        if ($code === null) {
            throw new RuntimeException(
                "La moneda {$profile->default_currency_id} del perfil fiscal no existe."
            );
        }

        return (string) $code;
    }

    /**
     * Los totales de la venta: son los del documento.
     */
    private function totals(int $saleId): object
    {
        $totals = $this->connection->table('sale_totals')->where('sale_id', $saleId)->first();

        if ($totals === null) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene totales: no se puede emitir su comprobante."
            );
        }

        return $totals;
    }

    /**
     * Las lineas de la venta, en su orden.
     *
     * @return list<object>
     */
    private function items(int $saleId): array
    {
        return $this->connection->table('sale_items')
            ->where('sale_id', $saleId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * El receptor del comprobante, si la venta tiene cliente.
     */
    private function customerId(object $sale): ?int
    {
        if ($sale->customer_id === null) {
            return null;
        }

        $exists = $this->connection->table('customers')
            ->where('id', $sale->customer_id)
            ->exists();

        if (! $exists) {
            throw new RuntimeException(
                "El cliente {$sale->customer_id} de la venta no existe: no se puede emitir su comprobante."
            );
        }

        return (int) $sale->customer_id;
    }

    /**
     * El documento fiscal: el borrador de un intento anterior, o uno nuevo.
     *
     * El borrador y el enlace con la venta se escriben en la MISMA
     * transaccion: o existen los dos, o ninguno. Un borrador sin enlace no
     * lo encontraria el reintento, y un enlace sin borrador dejaria la venta
     * apuntando a la nada.
     */
    private function document(
        int $saleId,
        object $sale,
        object $reservation,
        object $series,
        object $profile,
        object $totals,
        ?int $customerId,
    ): int {
        if ($sale->fiscal_document_id !== null) {
            $exists = $this->connection->table('fiscal_documents')
                ->where('id', $sale->fiscal_document_id)
                ->exists();

            if (! $exists) {
                throw new RuntimeException(
                    "La venta {$saleId} apunta al documento fiscal {$sale->fiscal_document_id}, que no existe."
                );
            }

            return (int) $sale->fiscal_document_id;
        }

        return $this->connection->transaction(function () use (
            $saleId,
            $sale,
            $reservation,
            $series,
            $profile,
            $totals,
            $customerId
        ): int {
            $documentId = (int) $this->connection->table('fiscal_documents')->insertGetId([
                'fiscal_profile_id' => (int) $series->fiscal_profile_id,
                'store_id' => (int) $sale->store_id,
                'series_id' => (int) $reservation->series_id,
                'number' => (int) $reservation->number,
                'reservation_id' => (int) $reservation->id,
                'document_type' => 'invoice',
                'status' => 'draft',
                'customer_id' => $customerId,
                'currency_id' => (int) $profile->default_currency_id,
                'subtotal' => (string) $totals->sub_total,
                'tax_total' => (string) $totals->tax_total,
                'total_amount' => (string) $totals->grand_total,
                'issue_date' => (string) $sale->sale_date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->connection->table('sales')->where('id', $saleId)->update([
                'fiscal_document_id' => $documentId,
                'updated_at' => now(),
            ]);

            return $documentId;
        });
    }

    /**
     * El desglose de impuestos del documento.
     *
     * Una fila por linea de la venta y una fila de CABECERA (`line_id` en
     * `null`), que lleva el impuesto del documento y el ajuste de redondeo.
     *
     * Es IDEMPOTENTE: si el documento ya tiene desglose (un intento
     * anterior lo escribio), no se duplica.
     *
     * @param  list<object>  $items
     */
    private function taxes(int $documentId, array $items, object $totals): void
    {
        $this->connection->transaction(function () use ($documentId, $items, $totals): void {
            $yaEsta = $this->connection->table('fiscal_document_taxes')
                ->where('fiscal_document_id', $documentId)
                ->exists();

            if ($yaEsta) {
                return;
            }

            $rows = [];

            foreach ($items as $item) {
                $rows[] = [
                    'fiscal_document_id' => $documentId,
                    // La linea de la VENTA: `line_id` es polimorfico y no
                    // lleva FK (M31 lo declara).
                    'line_id' => (int) $item->id,
                    'tax_type' => 'transfer',
                    'tax_category_id' => $item->tax_category_id === null ? null : (int) $item->tax_category_id,
                    'tax_rate_id' => $item->tax_rate_id === null ? null : (int) $item->tax_rate_id,
                    // El porcentaje CONGELADO: un cambio posterior de la tasa
                    // vigente no altera este documento.
                    'tax_rate_snapshot' => $item->tax_rate_snapshot === null ? null : (string) $item->tax_rate_snapshot,
                    'evidence_status' => $item->evidence_status,
                    'base_amount' => (string) $item->base_amount,
                    'tax_amount' => (string) $item->tax_amount,
                    'rounding_adjustment' => '0.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // El nodo de CABECERA: el impuesto del documento y su ajuste de
            // redondeo. `line_id` en `null` porque no es una linea: es el
            // total. Su base es cero a proposito: las bases son las de las
            // lineas, y aqui se declara el impuesto del documento.
            $rows[] = [
                'fiscal_document_id' => $documentId,
                'line_id' => null,
                'tax_type' => 'transfer',
                'tax_category_id' => null,
                'tax_rate_id' => null,
                'tax_rate_snapshot' => null,
                'evidence_status' => null,
                'base_amount' => '0.0000',
                'tax_amount' => (string) $totals->tax_total,
                'rounding_adjustment' => (string) $totals->rounding_adjustment,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->connection->table('fiscal_document_taxes')->insert($rows);
        });
    }

    /**
     * El borrador que se entrega al actor intermedio.
     *
     * @param  list<object>  $items
     */
    private function draft(
        object $sale,
        object $series,
        object $reservation,
        string $currencyCode,
        array $items,
    ): FiscalDocumentDraft {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = [
                'line_id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount' => (string) $item->discount,
                'base_amount' => (string) $item->base_amount,
                'tax_amount' => (string) $item->tax_amount,
                'line_total' => (string) $item->line_total,
                'tax_rate_snapshot' => $item->tax_rate_snapshot === null ? null : (string) $item->tax_rate_snapshot,
                'tax_category_id' => $item->tax_category_id === null ? null : (int) $item->tax_category_id,
                'tax_rate_id' => $item->tax_rate_id === null ? null : (int) $item->tax_rate_id,
                'evidence_status' => $item->evidence_status,
            ];
        }

        return new FiscalDocumentDraft(
            documentType: 'invoice',
            // El prefijo lo DECLARA la serie, no este codigo.
            seriesPrefix: (string) ($series->prefix ?? ''),
            // El numero RESERVADO, no uno nuevo: el reintento no quema
            // numeros.
            documentNumber: (int) $reservation->number,
            issuedAt: new DateTimeImmutable((string) $sale->sale_date),
            currencyCode: $currencyCode,
            lines: $lines,
        );
    }

    /**
     * La venta queda en `fiscal_failed`: el estado fiscal MACA el estado de
     * pago, porque `sales.status` es un unico enum.
     */
    private function markFailed(int $saleId): void
    {
        $this->connection->transaction(function () use ($saleId): void {
            $this->connection->table('sales')->where('id', $saleId)->update([
                'status' => 'fiscal_failed',
                'updated_at' => now(),
            ]);
        });
    }
}
