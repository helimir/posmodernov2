<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\ValueObjects\FiscalIssuanceOutcome;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * La EMISION fiscal de una venta (T2) y su reintento.
 *
 * REGLA que fija esta clase: *un fallo fiscal NO puede hacer perder una
 * venta*. La venta ya esta confirmada por T1: si la emision falla, la venta
 * EXISTE, queda en `fiscal_failed` (para que el reintento la encuentre) y
 * quien cobro recibe el AVISO. Un cobro que devolviera un error haria creer
 * al cajero que no se vendio, y cobraria otra vez: eso es PEOR que un
 * comprobante pendiente.
 *
 * Y EL SECUENCING NO SE ROMPE: la llamada al proveedor ocurre FUERA de la
 * transaccion de T1 (design D4, decision C5). Aqui no se abre ninguna
 * transaccion: el `FiscalIssuanceCoordinator` lee FUERA, llama al proveedor
 * FUERA y escribe en su propia unidad atomica.
 *
 * EL CANAL SE RESUELVE, NO SE FIJA: el proveedor lo deduce el resolutor de
 * las filas del perfil (entorno, proveedor y tipo), y el ambito dice de que
 * venta es. El binding del contenedor NO fija una implementacion: la deduce.
 */
final class FiscalIssuanceRunner
{
    /**
     * La accion de auditoria que deja el motivo de un fallo de emision.
     */
    private const ACCION_FALLO = 'fiscal_issuance_failed';

    /**
     * El tipo de la entidad a la que se atribuye el fallo.
     */
    private const ENTIDAD_VENTA = 'sale';

    public function __construct(
        private readonly Connection $connection,
        private readonly Container $container,
    ) {}

    /**
     * Emite el comprobante fiscal de la venta.
     *
     * @param  int  $saleId  La venta que se emite.
     * @param  int|null  $actorId  Quien emite; queda en la auditoria.
     */
    public function issue(int $saleId, ?int $actorId = null): FiscalIssuanceOutcome
    {
        $ambito = $this->container->make(FiscalProviderScope::class);

        try {
            // 1. El AMBITO: el canal se resuelve para la serie del documento
            //    de ESTA venta, y la venta viaja con el porque su numero de
            //    control es el que el documento va a reutilizar.
            $ambito->dentroDe($this->serieDelDocumento($saleId), $saleId);

            // 2. La EMISION. El coordinador lee fuera de transaccion, llama
            //    al proveedor fuera de transaccion y escribe en su propia
            //    unidad atomica.
            $documentId = $this->container->make(FiscalIssuanceCoordinator::class)
                ->issue($saleId, $actorId);
        } catch (Throwable $fallo) {
            // 3. El fallo NO borra la venta: la deja en `fiscal_failed` para
            //    que el reintento la encuentre, y devuelve el motivo.
            $this->marcaFallida($saleId);

            // 3.b El MOTIVO se AUDITA. La emision que falla pasa en una
            //     peticion que se responde y se olvida: si el motivo no
            //     queda escrito, la pantalla no puede explicar por que el
            //     comprobante no salio, y un fallo que no se explica no se
            //     puede reintentar a ciegas.
            $this->auditaElFallo($saleId, $fallo->getMessage(), $actorId);

            return FiscalIssuanceOutcome::fallido($fallo->getMessage(), $this->documentoDe($saleId));
        } finally {
            $ambito->limpiar();
        }

        return FiscalIssuanceOutcome::emitido($documentId, $this->controlDe($documentId));
    }

    /**
     * La SERIE del DOCUMENTO de la venta: la de su reserva.
     *
     * Es la MISMA reserva que el coordinador encuentra: el reintento no
     * reserva un numero nuevo, jams.
     */
    private function serieDelDocumento(int $saleId): int
    {
        $reservation = $this->connection->table('fiscal_document_reservations')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('series_role', 'document')
            ->orderBy('id')
            ->first();

        if ($reservation === null) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene reserva de documento: no se puede emitir su comprobante fiscal."
            );
        }

        return (int) $reservation->series_id;
    }

    /**
     * La venta queda en `fiscal_failed`: el estado fiscal avisa de que falta
     * el comprobante, y el comando de reintento la encuentra.
     *
     * El estado fiscal MACA el estado de pago, porque `sales.status` es un
     * unico enum. Un reintento exitoso lo RECALCULA desde los pagos.
     */
    private function marcaFallida(int $saleId): void
    {
        try {
            $this->connection->table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => 'fiscal_failed',
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // Si la venta ni siquiera existe, no hay nada que marcar: el
            // fallo que importa es el del canal, y ya se ha devuelto.
        }
    }

    /**
     * La AUDITORIA del fallo: quien fallo, sobre que venta y por que.
     *
     * `audit_logs` es la auditoria de NEGOCIO (M26): accion, entidad y
     * contexto. Es la sede natural del motivo de un fallo de emision, y es
     * consultable: la pantalla de la venta lo lee para explicar el fallo.
     *
     * Y LA AUDITORIA NO TAPA EL FALLO: si no se puede escribir, el motivo se
     * pierde, pero la venta sigue existiendo y el error original sigue siendo
     * el que se devuelve.
     */
    private function auditaElFallo(int $saleId, string $message, ?int $actorId): void
    {
        try {
            $storeId = $this->connection->table('sales')->where('id', $saleId)->value('store_id');

            $this->connection->table('audit_logs')->insert([
                'user_id' => $actorId,
                'store_id' => $storeId === null ? null : (int) $storeId,
                'action' => self::ACCION_FALLO,
                'entity_type' => self::ENTIDAD_VENTA,
                'entity_id' => $saleId,
                'context' => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // La auditoria del fallo NO puede convertir un fallo de emision
            // en un fallo de auditoria: el motivo se pierde, la venta no.
        }
    }

    /**
     * El DOCUMENTO fiscal de la venta, si lo tiene.
     */
    private function documentoDe(int $saleId): ?int
    {
        $documentId = $this->connection->table('sales')
            ->where('id', $saleId)
            ->value('fiscal_document_id');

        return $documentId === null ? null : (int) $documentId;
    }

    /**
     * El NUMERO DE CONTROL del documento emitido.
     */
    private function controlDe(int $documentId): ?string
    {
        $control = $this->connection->table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)
            ->value('control_number');

        return $control === null ? null : (string) $control;
    }
}
