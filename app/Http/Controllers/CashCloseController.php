<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\CashClose;
use App\Domain\Accounting\CashCloseRenderer;
use App\Domain\Accounting\CashCloseTemplate;
use App\Models\PrintJob;
use App\Models\PrintTarget;
use App\Print\CashClosePrintPayload;
use App\Print\PrintJobDispatcher;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * El Z IMPRIMIBLE: el cierre de caja en papel y en la impresora.
 *
 * REGLA que fija esta clase: *el Z se imprime con el contenido EXACTO del Z
 * sellado*. Las dos rutas (el papel y la impresora) leen la MISMA fila de
 * `cash_closes`, a traves del MISMO modelo (`CashCloseTemplate`). Ninguna de
 * las dos recalcula una cifra: si el papel se compusiera con las cifras del
 * dia, una venta corregida despues del cierre cambiaria el papel, y el papel
 * diria una cosa y el Z otra.
 *
 * Y UN DIA ABIERTO NO TIENE Z: la ruta no lo sirve, porque un dia abierto no
 * es un hecho cerrado. Un Z es un HECHO, y un hecho que no ha ocurrido no se
 * imprime.
 *
 * Y UN Z ALTERADO NO SE IMPRIME: si su firma no cuadra con su contenido,
 * alguien lo toco, y un documento tocado no se pone en circulacion.
 */
class CashCloseController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CashClose $closes,
        private readonly CashCloseRenderer $renderer,
        private readonly PrintJobDispatcher $dispatcher,
    ) {}

    /**
     * El Z en PAPEL: el HTML con el CSS de la impresora termica.
     */
    public function show(Request $request): Response
    {
        try {
            $contexto = $this->contexto($request);
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        $z = $this->closes->current($contexto['store_id'], $contexto['business_date']);

        abort_if($z === null, 404, 'Esta caja del dia no tiene ningun Z sellado: todavia no hay un cierre que imprimir.');

        abort_if(
            ! CashClose::verify($z),
            409,
            'La firma del Z sellado no cuadra con su contenido: alguien lo altero y no se imprime.'
        );

        $plantilla = $this->plantilla($z, $contexto);

        return response()->view('cash.z', [
            'html' => $this->renderer->render($plantilla),
            'denomination' => CashClosePrintPayload::DENOMINACION,
            'businessDate' => $plantilla->businessDate,
            'storeName' => $plantilla->storeName,
        ]);
    }

    /**
     * ENCOLAR el Z a un destino de impresion de la tienda.
     *
     * El destino tiene que ser FISICO: el transporte `browser` no tiene
     * endpoint, porque el navegador es el destino.
     *
     * Y EL FALLO DEL RENDERER NO SE RODEA: si el destino es demasiado estrecho
     * para el Z, el error se devuelve con su mensaje intacto. Un Z recortado es
     * un Z falso.
     *
     * Y REIMPRIMIR UN Z DEJA CONSTANCIA: la primera impresion no tiene motivo
     * (no es una reimpresion), pero a partir de la segunda el motivo es
     * OBLIGATORIO: el Z es el documento del cierre, y sacarlo otra vez tiene
     * que decir por que.
     */
    public function imprimir(Request $request): JsonResponse
    {
        try {
            $contexto = $this->contexto($request);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $z = $this->closes->current($contexto['store_id'], $contexto['business_date']);

        if ($z === null) {
            return response()->json([
                'errorMsg' => 'Esta caja del dia no tiene ningun Z sellado: todavia no hay un cierre que imprimir.',
            ], 422);
        }

        if (! CashClose::verify($z)) {
            return response()->json([
                'errorMsg' => 'La firma del Z sellado no cuadra con su contenido: alguien lo altero y no se imprime.',
            ], 422);
        }

        $destino = $this->destino($request, $contexto['store_id']);

        if ($destino === null) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no pertenece a la tienda del Z.',
            ], 422);
        }

        if (! in_array((string) $destino->transport, PrintTarget::TRANSPORTES_FISICOS, true)) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no escribe en una impresora: '
                    .'el transporte browser se sirve en el navegador, no por bytes.',
            ], 422);
        }

        $ancho = $destino->char_per_line === null ? null : (int) $destino->char_per_line;

        if ($ancho === null) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no declara el ancho de linea: no se puede componer el Z.',
            ], 422);
        }

        // EL MOTIVO: la reimpresion de un Z deja constancia.
        $motivo = trim((string) ($request->input('reason') ?? ''));

        $anteriores = $this->trabajosDelZ($contexto['store_id'], (int) $z->id);

        $esReimpresion = $anteriores !== [];

        if ($esReimpresion && $motivo === '') {
            return response()->json([
                'errorMsg' => 'Este Z ya se ha impreso: reimprimirlo necesita un MOTIVO, '
                    .'porque el Z es el documento del cierre y hay que saber por que se saca otra vez.',
            ], 422);
        }

        $plantilla = $this->plantilla($z, $contexto);

        try {
            $jobId = $this->dispatcher->dispatch(
                $contexto['store_id'],
                (int) $destino->id,
                CashClosePrintPayload::fromTemplate($plantilla, $ancho),
                $request->user()?->id,
                $motivo === '' ? null : $motivo,
                $esReimpresion ? (int) $anteriores[0]->id : null,
                PrintJob::KIND_CASH_CLOSE_Z,
                (int) $z->id,
            );
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        // LA CONSTANCIA: un Z impreso queda anotado, y un Z reimpreso queda
        // anotado CON el motivo y con el trabajo anterior.
        (new AuditWriter($this->connection))->recordFor(
            $request,
            $esReimpresion ? 'cash_close.reprinted' : 'cash_close.printed',
            'cash_close',
            (int) $z->id,
            [
                'store_id' => $contexto['store_id'],
                'business_date' => $contexto['business_date'],
                'print_job_id' => $jobId,
                'print_target_id' => (int) $destino->id,
                'reason' => $motivo === '' ? null : $motivo,
                'reprint_of_job_id' => $esReimpresion ? (int) $anteriores[0]->id : null,
            ],
            (int) $contexto['store_id']
        );

        return response()->json([
            'job_id' => $jobId,
            'cash_close_id' => (int) $z->id,
            'reprint' => $esReimpresion,
        ], 201);
    }

    /**
     * El CONTEXTO de la peticion: la tienda activa, su moneda y la fecha de
     * negocio.
     *
     * @return array{store_id: int, business_date: string, currency_code: string, decimals: int}
     */
    private function contexto(Request $request): array
    {
        $contexto = new PosContext($this->connection, $request->user()?->id);

        $store = $contexto->store();
        $moneda = $contexto->currency();

        return [
            'store_id' => (int) $store->id,
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'currency_code' => (string) $moneda['code'],
            'decimals' => (int) $moneda['decimals'],
        ];
    }

    /**
     * El MODELO del Z, a partir de la fila sellada.
     *
     * @param  array{store_id: int, business_date: string, currency_code: string, decimals: int}  $contexto
     */
    private function plantilla(object $z, array $contexto): CashCloseTemplate
    {
        $tienda = $this->connection->table('stores')
            ->where('id', $contexto['store_id'])
            ->value('name');

        return CashCloseTemplate::fromClose(
            $this->connection,
            $z,
            $tienda === null ? '' : (string) $tienda,
            $contexto['currency_code'],
            $contexto['decimals'],
        );
    }

    /**
     * El DESTINO de impresion de la tienda, o `null` si no es de la tienda.
     */
    private function destino(Request $request, int $storeId): ?PrintTarget
    {
        $targetId = (int) $request->input('print_target_id');

        $destino = PrintTarget::find($targetId);

        if ($destino === null || (int) $destino->store_id !== $storeId) {
            return null;
        }

        return $destino;
    }

    /**
     * Los TRABAJOS de impresion de un Z: los que ya salieron de la caja.
     *
     * @return list<object>
     */
    private function trabajosDelZ(int $storeId, int $cashCloseId): array
    {
        return $this->connection->table('print_jobs')
            ->where('store_id', $storeId)
            ->where('cash_close_id', $cashCloseId)
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }
}
