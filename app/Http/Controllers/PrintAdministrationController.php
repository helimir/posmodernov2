<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\Store;
use App\Print\PrintConnectionTester;
use App\Print\PrinterCatalog;
use App\Print\PrintProfiles;
use App\Print\PrintTargetData;
use App\Print\PrintTargetRegistry;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La ADMINISTRACION de la impresion: el catalogo de impresoras y los
 * destinos de impresion de la tienda.
 *
 * REGLA que fija esta clase: *una tienda declara sus impresoras y sus
 * destinos, y el sistema no adivina nada de ellos*. Hasta ahora los
 * destinos se creaban a mano en la base: nadie decia que impresora tiene una
 * tienda, ni con que perfil, ni con que ancho, ni si admite QR. Esta
 * pantalla es la que lo declara.
 *
 * Y EL DOMINIO DECIDE: el `PrintTargetRegistry` y el `PrinterCatalog` son los
 * que validan lo que se declara (el endpoint, el ancho, la codepagina, la
 * unicidad del codigo). Aqui solo se traduce su fallo al CONTRATO de la API:
 * un 422 con `{"errorMsg": "..."}`, el mismo de siempre.
 *
 * Y TODO CAMBIO SE AUDITA: quien lo hizo, sobre que y desde donde. Un
 * destino de impresion es CONFIGURACION, y la configuracion se audita.
 *
 * Y EL DESTINO ES POR TIENDA: la pantalla enseña los destinos de la tienda
 * del contexto, la misma que usa la caja, y editar uno no toca los de las
 * demas.
 */
class PrintAdministrationController extends Controller
{
    /**
     * La ACCION de la auditoria: se ha dado de alta una impresora.
     */
    private const ACCION_IMPRESORA_CREADA = 'printer.created';

    /**
     * La ACCION de la auditoria: se ha editado una impresora.
     */
    private const ACCION_IMPRESORA_ACTUALIZADA = 'printer.updated';

    /**
     * La ACCION de la auditoria: se ha dado de alta un destino.
     */
    private const ACCION_DESTINO_CREADO = 'print_target.created';

    /**
     * La ACCION de la auditoria: se ha editado un destino.
     */
    private const ACCION_DESTINO_ACTUALIZADO = 'print_target.updated';

    /**
     * La ENTIDAD de la auditoria: la impresora.
     */
    private const ENTIDAD_IMPRESORA = 'printer';

    /**
     * La ENTIDAD de la auditoria: el destino.
     */
    private const ENTIDAD_DESTINO = 'print_target';

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditWriter $audit,
        private readonly PrinterCatalog $printers,
        private readonly PrintTargetRegistry $targets,
        private readonly PrintConnectionTester $tester,
    ) {}

    /**
     * La PANTALLA del catalogo de impresoras.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/printing/printers.tsx`:
     *
     *  - `printers`: lista de `{ id, code, name, is_active, targets_count }`.
     */
    public function printers(Request $request): Response
    {
        return Inertia::render('printing/printers', [
            'printers' => $this->impresoras(),
        ]);
    }

    /**
     * La PANTALLA de los destinos de impresion de la tienda.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/printing/targets.tsx`:
     *
     *  - `targets`: lista de `{ id, name, printer_id, printer_code,
     *    printer_name, transport, endpoint, printer_profile, char_per_line,
     *    charset, max_qr_payload_bytes, supports_qr, is_active }`.
     *  - `printers`: el catalogo, para el selector.
     *  - `profiles`: los perfiles ESC/POS de la libreria, con sus
     *    codepaginas codificables, para los selectores.
     *  - `store`: la tienda activa, la misma que usa la caja.
     */
    public function targets(Request $request): Response
    {
        $tienda = $this->tienda($request);

        return Inertia::render('printing/targets', [
            'targets' => $this->destinos((int) $tienda->id),
            'printers' => $this->impresoras(),
            'profiles' => PrintProfiles::todos(),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * El CATALOGO de impresoras, en JSON.
     *
     * @return array<string, mixed>
     */
    public function indexPrinters(Request $request): JsonResponse
    {
        return response()->json(['printers' => $this->impresoras()]);
    }

    /**
     * Los DESTINOS de la tienda activa, en JSON.
     *
     * @return array<string, mixed>
     */
    public function indexTargets(Request $request): JsonResponse
    {
        $tienda = $this->tienda($request);

        return response()->json([
            'targets' => $this->destinos((int) $tienda->id),
            'printers' => $this->impresoras(),
            'profiles' => PrintProfiles::todos(),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * El ALTA de una impresora del catalogo.
     */
    public function storePrinter(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $id = $this->printers->create((string) $datos['code'], (string) $datos['name']);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $impresora = Printer::query()->findOrFail($id);

        $this->audit->record(
            $request,
            self::ACCION_IMPRESORA_CREADA,
            self::ENTIDAD_IMPRESORA,
            (int) $impresora->id,
            [
                'code' => (string) $impresora->code,
                'name' => (string) $impresora->name,
            ],
        );

        return response()->json([
            'id' => (int) $impresora->id,
            'code' => (string) $impresora->code,
            'name' => (string) $impresora->name,
            'is_active' => (bool) $impresora->is_active,
        ], 201);
    }

    /**
     * La EDICION de una impresora del catalogo.
     */
    public function updatePrinter(Request $request, Printer $printer): JsonResponse
    {
        $datos = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $this->printers->update(
                (int) $printer->id,
                (string) $datos['code'],
                (string) $datos['name'],
                (bool) $datos['is_active'],
            );
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $impresora = Printer::query()->findOrFail((int) $printer->id);

        $this->audit->record(
            $request,
            self::ACCION_IMPRESORA_ACTUALIZADA,
            self::ENTIDAD_IMPRESORA,
            (int) $impresora->id,
            [
                'code' => (string) $impresora->code,
                'name' => (string) $impresora->name,
                'is_active' => (bool) $impresora->is_active,
            ],
        );

        return response()->json([
            'id' => (int) $impresora->id,
            'code' => (string) $impresora->code,
            'name' => (string) $impresora->name,
            'is_active' => (bool) $impresora->is_active,
        ]);
    }

    /**
     * El ALTA de un destino de impresion en la tienda activa.
     */
    public function storeTarget(Request $request): JsonResponse
    {
        $tienda = $this->tienda($request);

        $datos = $this->validaElDestino($request);

        try {
            $id = $this->targets->create((int) $tienda->id, $datos);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $destino = PrintTarget::query()->findOrFail($id);

        $this->audit->record(
            $request,
            self::ACCION_DESTINO_CREADO,
            self::ENTIDAD_DESTINO,
            (int) $destino->id,
            $this->contextoDelDestino($destino),
        );

        return response()->json($this->targetProps($destino), 201);
    }

    /**
     * La EDICION de un destino de impresion.
     */
    public function updateTarget(Request $request, PrintTarget $target): JsonResponse
    {
        $tienda = $this->tienda($request);

        if ((int) $target->store_id !== (int) $tienda->id) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no pertenece a la tienda activa.',
            ], 422);
        }

        $datos = $this->validaElDestino($request);

        try {
            $this->targets->update((int) $target->id, $datos);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $destino = PrintTarget::query()->findOrFail((int) $target->id);

        $this->audit->record(
            $request,
            self::ACCION_DESTINO_ACTUALIZADO,
            self::ENTIDAD_DESTINO,
            (int) $destino->id,
            $this->contextoDelDestino($destino),
        );

        return response()->json($this->targetProps($destino));
    }

    /**
     * La PRUEBA de la conexion de un destino.
     *
     * Abre la conexion y la cierra: no escribe un ticket de verdad. Y decide
     * el TRANSPORTE quien dice quien escribe: el `browser` no tiene destino
     * fisico, y el `lan_agent` lo escribe el agente, no el servidor.
     *
     * Y ADEMAS AVISA del ancho insuficiente: con el ancho declarado y el
     * nombre de la impresora hay lineas que no caben, y eso es el aviso
     * temprano del ticket mudo.
     */
    public function testConnection(Request $request, PrintTarget $target): JsonResponse
    {
        $tienda = $this->tienda($request);

        if ((int) $target->store_id !== (int) $tienda->id) {
            return response()->json([
                'errorMsg' => 'El destino de impresion no pertenece a la tienda activa.',
            ], 422);
        }

        $informe = $this->tester->probar($target);

        return response()->json([
            'status' => $informe->status,
            'message' => $informe->message,
            'reason' => $informe->reason,
            'warnings' => $informe->warnings,
        ]);
    }

    /**
     * Los DATOS del destino, validados por la costura.
     *
     * Lo que se valida aqui es lo que la costura sabe: que los campos
     * vienen, que el transporte es uno de los tres y que la impresora
     * existe. Lo que el destino DECLARA (el endpoint, el ancho, la
     * codepagina) lo valida el dominio.
     */
    private function validaElDestino(Request $request): PrintTargetData
    {
        $datos = $request->validate([
            'printer_id' => ['required', 'integer', 'exists:printers,id'],
            'name' => ['required', 'string', 'max:255'],
            'transport' => ['required', Rule::in(['browser', 'lan_agent', 'server_socket'])],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'printer_profile' => ['required', 'string', 'max:100'],
            'char_per_line' => ['nullable', 'integer', 'min:1'],
            'charset' => ['nullable', 'string', 'max:20'],
            'max_qr_payload_bytes' => ['required', 'integer', 'min:1'],
            'supports_qr' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $ancho = array_key_exists('char_per_line', $datos) && $datos['char_per_line'] !== null
            ? (int) $datos['char_per_line']
            : null;

        return new PrintTargetData(
            printerId: (int) $datos['printer_id'],
            name: (string) $datos['name'],
            transport: (string) $datos['transport'],
            endpoint: $datos['endpoint'] ?? null,
            printerProfile: (string) $datos['printer_profile'],
            charPerLine: $ancho,
            charset: (string) ($datos['charset'] ?? ''),
            maxQrPayloadBytes: (int) $datos['max_qr_payload_bytes'],
            supportsQr: (bool) $datos['supports_qr'],
            isActive: (bool) $datos['is_active'],
        );
    }

    /**
     * La TIENDA del contexto: la misma que usa la caja.
     */
    private function tienda(Request $request): Store
    {
        $context = new PosContext($this->connection, $request->user()?->id);

        try {
            return $context->store();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }
    }

    /**
     * El CATALOGO de impresoras, con el numero de destinos de cada una.
     *
     * @return list<array<string, mixed>>
     */
    private function impresoras(): array
    {
        $conteos = $this->connection->table('print_targets')
            ->selectRaw('printer_id, COUNT(*) as total')
            ->groupBy('printer_id')
            ->pluck('total', 'printer_id');

        $filas = Printer::query()->orderBy('code')->get(['id', 'code', 'name', 'is_active']);

        $impresoras = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->id;

            $impresoras[] = [
                'id' => $id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'is_active' => (bool) $fila->is_active,
                'targets_count' => (int) ($conteos->get($id) ?? 0),
            ];
        }

        return $impresoras;
    }

    /**
     * Los DESTINOS de una tienda, con su impresora.
     *
     * @return list<array<string, mixed>>
     */
    private function destinos(int $storeId): array
    {
        $filas = $this->connection->table('print_targets')
            ->join('printers', 'printers.id', '=', 'print_targets.printer_id')
            ->where('print_targets.store_id', $storeId)
            ->orderBy('print_targets.name')
            ->get([
                'print_targets.id as id',
                'print_targets.name as name',
                'print_targets.printer_id as printer_id',
                'printers.code as printer_code',
                'printers.name as printer_name',
                'print_targets.transport as transport',
                'print_targets.endpoint as endpoint',
                'print_targets.printer_profile as printer_profile',
                'print_targets.char_per_line as char_per_line',
                'print_targets.charset as charset',
                'print_targets.max_qr_payload_bytes as max_qr_payload_bytes',
                'print_targets.supports_qr as supports_qr',
                'print_targets.is_active as is_active',
            ]);

        $destinos = [];

        foreach ($filas as $fila) {
            $destinos[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'printer_id' => (int) $fila->printer_id,
                'printer_code' => (string) $fila->printer_code,
                'printer_name' => (string) $fila->printer_name,
                'transport' => (string) $fila->transport,
                'endpoint' => $fila->endpoint === null ? null : (string) $fila->endpoint,
                'printer_profile' => (string) $fila->printer_profile,
                'char_per_line' => $fila->char_per_line === null ? null : (int) $fila->char_per_line,
                'charset' => (string) $fila->charset,
                'max_qr_payload_bytes' => (int) $fila->max_qr_payload_bytes,
                'supports_qr' => (bool) $fila->supports_qr,
                'is_active' => (bool) $fila->is_active,
            ];
        }

        return $destinos;
    }

    /**
     * Un DESTINO, con su impresora, para la respuesta de la API.
     *
     * @return array<string, mixed>
     */
    private function targetProps(PrintTarget $destino): array
    {
        $impresora = Printer::query()->find((int) $destino->printer_id);

        return [
            'id' => (int) $destino->id,
            'name' => (string) $destino->name,
            'printer_id' => (int) $destino->printer_id,
            'printer_code' => $impresora === null ? null : (string) $impresora->code,
            'printer_name' => $impresora === null ? null : (string) $impresora->name,
            'transport' => (string) $destino->transport,
            'endpoint' => $destino->endpoint === null ? null : (string) $destino->endpoint,
            'printer_profile' => (string) $destino->printer_profile,
            'char_per_line' => $destino->char_per_line === null ? null : (int) $destino->char_per_line,
            'charset' => (string) $destino->charset,
            'max_qr_payload_bytes' => (int) $destino->max_qr_payload_bytes,
            'supports_qr' => (bool) $destino->supports_qr,
            'is_active' => (bool) $destino->is_active,
        ];
    }

    /**
     * El CONTEXTO de la auditoria de un destino: lo que la tienda declara.
     *
     * @return array<string, mixed>
     */
    private function contextoDelDestino(PrintTarget $destino): array
    {
        return [
            'name' => (string) $destino->name,
            'printer_id' => (int) $destino->printer_id,
            'transport' => (string) $destino->transport,
            'endpoint' => $destino->endpoint,
            'printer_profile' => (string) $destino->printer_profile,
            'char_per_line' => $destino->char_per_line === null ? null : (int) $destino->char_per_line,
            'charset' => (string) $destino->charset,
            'max_qr_payload_bytes' => (int) $destino->max_qr_payload_bytes,
            'supports_qr' => (bool) $destino->supports_qr,
            'is_active' => (bool) $destino->is_active,
        ];
    }

    /**
     * La TIENDA del contexto, para la pantalla.
     *
     * @return array{id: int, name: string, code_name: string|null}
     */
    private function storeProps(Store $tienda): array
    {
        return [
            'id' => (int) $tienda->id,
            'name' => (string) $tienda->name,
            'code_name' => $tienda->code_name,
        ];
    }
}
