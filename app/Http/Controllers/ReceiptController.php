<?php

namespace App\Http\Controllers;

use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;
use App\Models\PrintTarget;
use App\Print\PrintJobDispatcher;
use App\Print\ReceiptPrintPayload;
use App\Support\Fiscal\StoreAccess;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * El COMPROBANTE FISCAL: el HTML que se ve y se imprime.
 *
 * REGLA que fija esta clase: *el comprobante es del DOCUMENTO, y el documento
 * es de la TIENDA*. Un comprobante de otra tienda es un 404: no se le dice a
 * un cajero que el documento existe.
 *
 * Y UN BORRADOR NO ES UN COMPROBANTE: un documento en `draft` no se sirve.
 * El papel se da cuando el comprobante esta emitido, no antes.
 *
 * ESTE ES EL TRANSPORTE `browser`: no hay bytes ESC/POS aqui, hay HTML con
 * el CSS de la impresora termica. El contenido es el MISMO que el del
 * ticket (`ReceiptPrintPayload` parte del mismo modelo de plantilla), asi que
 * el papel y la pantalla cuentan la misma cosa.
 */
final class ReceiptController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ReceiptRenderer $renderer,
        private readonly PrintJobDispatcher $dispatcher,
        private readonly StoreAccess $access,
    ) {}

    /**
     * El COMPROBANTE en HTML, listo para imprimir.
     */
    public function show(Request $request, int $documento): Response
    {
        $document = $this->documentoDeLaTienda($request, $documento);

        $template = ReceiptTemplate::fromDocument($this->connection, (int) $document->id);

        $store = $this->connection->table('stores')
            ->where('id', (int) $document->store_id)
            ->value('name');

        return response()->view('receipts.show', [
            'html' => $this->renderer->render($template),
            // El QR viaja si el documento lo trae: es opcional por canal, y
            // un QR que no existe no se inventa.
            'qr' => $template->qrContent,
            'denomination' => $template->denomination,
            'documentNumber' => $template->documentNumber,
            'controlNumber' => $template->controlNumber,
            'issuedAt' => $template->issuedAt->format('d/m/Y H:i:s'),
            'total' => $template->totalAmount,
            'currency' => $template->currencyCode,
            'storeName' => $store === null ? null : (string) $store,
        ]);
    }

    /**
     * ENCOLAR el comprobante a un destino de impresion de la tienda.
     *
     * El destino tiene que ser FISICO: el transporte `browser` no tiene
     * endpoint, porque el navegador es el destino.
     *
     * Y EL FALLO DEL RENDERER NO SE RODEA: si el destino no admite el QR, o
     * el ancho no cabe, el `EscposRenderer` falla con un error que explica
     * que pasa. El error se devuelve al que lo pidio (422) con su mensaje
     * intacto: no se degrada el ticket, no se quita el QR y no se imprime un
     * ticket mudo.
     */
    public function imprimir(Request $request, int $documento): JsonResponse
    {
        $document = $this->documentoDeLaTienda($request, $documento);

        $targetId = (int) $request->input('print_target_id');

        $target = PrintTarget::find($targetId);

        if ($target === null || (int) $target->store_id !== (int) $document->store_id) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no pertenece a la tienda del comprobante.',
            ], 422);
        }

        if (! in_array((string) $target->transport, PrintTarget::TRANSPORTES_FISICOS, true)) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no escribe en una impresora: '
                    .'el transporte browser se sirve en el navegador, no por bytes.',
            ], 422);
        }

        $template = ReceiptTemplate::fromDocument($this->connection, (int) $document->id);

        try {
            $jobId = $this->dispatcher->dispatch(
                (int) $document->store_id,
                (int) $target->id,
                ReceiptPrintPayload::fromTemplate($template),
                $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json(['job_id' => $jobId], 201);
    }

    /**
     * El DOCUMENTO que el usuario puede ver, o un 404.
     *
     * Un documento que no existe, o de una tienda a la que el usuario no
     * tiene acceso, es un 404: no se le dice a un cajero que el comprobante
     * existe.
     *
     * Y un documento en `draft` NO se sirve: un borrador no es un
     * comprobante.
     */
    private function documentoDeLaTienda(Request $request, int $documentId): object
    {
        $document = $this->connection->table('fiscal_documents')->where('id', $documentId)->first();

        abort_if($document === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $document->store_id),
            404
        );

        abort_if(
            (string) $document->status !== 'issued',
            409,
            'Este comprobante fiscal todavia no ha sido emitido: no se puede imprimir.'
        );

        return $document;
    }
}
