<?php

namespace App\Print;

use App\Domain\Fiscal\ReceiptTemplate;
use App\Models\PrintTarget;
use Illuminate\Database\Connection;
use Throwable;

/**
 * La COLA del ticket del cobro: encola el trabajo de impresion de una venta.
 *
 * REGLA que fija esta clase: *el ticket se encola al cobrar, y el fallo de
 * la impresora NUNCA puede perder una venta*.
 *
 * POR QUE ESTA CLASE EXISTE, Y POR QUE NO ESTA EN EL DOMINIO DEL COBRO:
 *
 *  - EL TRABAJO SE ENCOLA FUERA de la transaccion de la venta. Los bytes se
 *    generan ANTES de escribir el trabajo (eso hace el despachador, para que
 *    el trabajo no se cree a medias), y generar bytes es TRABAJO, no una
 *    escritura: dentro de la transaccion alargaria el bloqueo de la venta.
 *  - EL FALLO NO SE PROPAGA. La venta ya esta confirmada: si el destino no
 *    puede representar el ticket, el cobro responde 201 IGUAL, con el aviso,
 *    y el ticket queda pendiente de que alguien lo imprima. Un cobro que
 *    devolviera un error haria creer al cajero que no se vendio, y cobraria
 *    otra vez: eso es PEOR que un ticket pendiente.
 *
 * Y EL CONTENIDO DEL TICKET ES EL DEL COMPROBANTE: el `PrintPayload` sale de
 * la `ReceiptTemplate` del documento (el puente es `ReceiptPrintPayload`), asi
 * que el ticket lleva el MISMO numero de documento, el MISMO numero de
 * control, el mismo total y el mismo QR. Una venta tiene UN numero de
 * control, y el ticket no puede llevar otro.
 *
 * Y EL DESTINO NO SE ADIVINA: si la tienda tiene UN destino activo, el ticket
 * va ahi; si tiene VARIOS, o ninguno, no se encola y se dice por que. El
 * sistema no elige una impresora por el cajero.
 */
final readonly class CheckoutPrintQueue
{
    public function __construct(
        private Connection $connection,
        private PrintJobDispatcher $dispatcher,
    ) {}

    /**
     * Encola el ticket de la venta y devuelve lo que paso.
     *
     * NUNCA lanza: un fallo del destino es un resultado, no una excepcion. La
     * venta ya esta confirmada, y confirmada es confirmada.
     *
     * @param  int  $saleId  La venta cuyo ticket se encola.
     * @param  int|null  $actorId  Quien cobra; queda en la auditoria del
     *                             trabajo.
     */
    public function encolar(int $saleId, ?int $actorId = null): PrintQueueOutcome
    {
        // 1. LA VENTA. De ella salen la tienda y el comprobante.
        $venta = $this->connection->table('sales')->where('id', $saleId)->first();

        if ($venta === null) {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::SIN_COMPROBANTE,
                "La venta {$saleId} no existe: no hay ticket que encolar."
            );
        }

        $storeId = (int) $venta->store_id;

        // 2. EL INTERRUPTOR DE LA TIENDA.
        //
        //    Es el PRIMER motivo que se mira: con el interruptor apagado no
        //    se imprime nada, y ningun otro motivo explica eso.
        if (! $this->imprimeAutomaticamente($storeId)) {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::AUTO_PRINT_APAGADO,
                'La tienda no imprime automaticamente: el ticket sale desde la ficha de la venta.'
            );
        }

        // 3. EL DESTINO.
        //
        //    SOLO los destinos FISICOS cuentan: el transporte `browser` no
        //    escribe bytes (no tiene endpoint), y un ticket para el navegador
        //    no es un ticket para la impresora.
        $destinos = $this->destinosActivos($storeId);

        if ($destinos === []) {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::SIN_DESTINO,
                'La tienda no tiene ningun destino de impresion activo: no hay donde imprimir el ticket.'
            );
        }

        if (count($destinos) > 1) {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::DESTINO_AMBIGUO,
                'La tienda tiene '.count($destinos).' destinos de impresion activos: '
                .'el sistema no elige por el cajero, y el ticket queda pendiente.'
            );
        }

        // 4. EL COMPROBANTE. El ticket es el comprobante en otro papel: sin
        //    comprobante emitido no hay ticket, porque sus datos son los del
        //    comprobante.
        $documentId = $venta->fiscal_document_id === null
            ? null
            : (int) $venta->fiscal_document_id;

        if ($documentId === null) {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::SIN_COMPROBANTE,
                'La venta no tiene comprobante fiscal: no hay ticket que imprimir.'
            );
        }

        $documento = $this->connection->table('fiscal_documents')
            ->where('id', $documentId)
            ->first();

        if ($documento === null || (string) $documento->status !== 'issued') {
            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::COMPROBANTE_NO_EMITIDO,
                'El comprobante fiscal de la venta no esta emitido: no hay ticket que imprimir.'
            );
        }

        // 5. EL TICKET Y EL TRABAJO.
        //
        //    Los bytes se generan ANTES de escribir el trabajo (lo hace el
        //    despachador), y aqui NO se escribe nada a medias: un fallo deja
        //    la cola como estaba.
        try {
            $template = ReceiptTemplate::fromDocument($this->connection, $documentId);

            $jobId = $this->dispatcher->dispatch(
                $storeId,
                (int) $destinos[0]->id,
                ReceiptPrintPayload::fromTemplate($template),
                $actorId
            );
        } catch (Throwable $fallo) {
            // El fallo se VE (en el log y en la respuesta) y NO tira la
            // venta: la venta ya esta confirmada.
            report($fallo);

            return PrintQueueOutcome::noEncolado(
                PrintQueueOutcome::FALLO_DEL_DESTINO,
                'El destino de impresion no pudo generar el ticket: '.$fallo->getMessage()
            );
        }

        return PrintQueueOutcome::encolado($jobId);
    }

    /**
     * EL INTERRUPTOR de la tienda: `store_settings.auto_print`.
     *
     * Es DATO de la tienda, y su DEFECTO es apagado: una tienda que no lo
     * declara no imprime automaticamente, y el cajero imprime desde la ficha.
     */
    private function imprimeAutomaticamente(int $storeId): bool
    {
        $valor = $this->connection->table('store_settings')
            ->where('store_id', $storeId)
            ->value('auto_print');

        // PostgreSQL devuelve el booleano como `bool`; se acepta tambien la
        // forma textual por si el driver cambia.
        return in_array($valor, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * Los DESTINOS ACTIVOS de la tienda, los que escriben bytes.
     *
     * @return list<object>
     */
    private function destinosActivos(int $storeId): array
    {
        return $this->connection->table('print_targets')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereIn('transport', PrintTarget::TRANSPORTES_FISICOS)
            ->orderBy('id')
            ->get()
            ->all();
    }
}
