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
     * El documento fiscal de la venta, si lo tiene.
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
