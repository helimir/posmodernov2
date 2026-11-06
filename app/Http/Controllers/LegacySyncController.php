<?php

namespace App\Http\Controllers;

use App\Sync\Legacy\LegacyBatchApplier;
use App\Sync\Legacy\LegacySyncVerifier;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El ENDPOINT del sync legacy (`POST /api/sync`).
 *
 * REGLA que fija este controlador: la respuesta es la del legacy, con sus
 * TRES claves (`status`, `message`, `for`) y su codigo HTTP. El sync NO usa
 * el `{errorMsg}` del resto de la API: tiene su propio contrato, y el
 * terminal legacy lee estas tres claves. Si el maestro cambiara la forma, el
 * terminal se quedaria sin sincronizar.
 *
 * El maestro es el que autentica al terminal, asi que el cuerpo se lee
 * CRUDO: el `data` es un string JSON con la doble codificacion del legacy
 * (el lote viaja como texto dentro del cuerpo) y el tamano se mide sobre el
 * cuerpo COMPLETO.
 *
 * Los codigos son los del legacy (`sync.php`):
 *
 * | Situacion                       | Codigo | Mensaje                                       |
 * |---------------------------------|--------|-----------------------------------------------|
 * | lote autenticado y aplicado     | 200    | `sync successfully done`                      |
 * | lote autenticado y NO aplicado  | 500    | `Sync failed`                                 |
 * | sincronizacion apagada          | 403    | `Synchronization is disabled on this server`  |
 * | trafico desde localhost         | 403    | `Invalid Action`                              |
 * | peticion sin HTTPS               | 403    | `HTTPS is required`                           |
 * | IP no admitida                  | 403    | `Invalid Action`                              |
 * | cuerpo demasiado grande         | 413    | `Payload too large`                           |
 * | demasiadas sentencias           | 413    | `Too many statements`                         |
 * | cuerpo que no es un objeto JSON | 400    | `Invalid Action`                              |
 * | accion que no es `sync`         | 400    | `Invalid Action`                              |
 * | lote vacio                      | 400    | `Nothing to sync`                             |
 * | autenticacion fallida           | 401    | `Invalid Action` (opaca)                      |
 *
 * El TRAFICO se filtra ANTES de autenticar, como en el legacy: el lote lleva
 * datos de venta y viaja firmado, y una peticion que no llega por HTTPS, que
 * viene de loopback o que viene de una IP no admitida no tiene por que
 * gastarse una verificacion de firma.
 *
 * El LOTE se APLICA de verdad, con el aplicador, y el fallo de aplicacion
 * responde `500 Sync failed` sin filtrar el motivo: el motivo va al log del
 * servidor, como todo lo demas.
 *
 * El ADAPTADOR (`sync.legacy_adapter_enabled`) es el interruptor del modulo
 * (0.1.3): apagado, el maestro no traduce ni aplica, porque el mapa de
 * traduccion todavia no esta sembrado ni auditado. El terminal queda
 * PAUSADO, no CONGELADO: sigue recibiendo exito y no acumula log. Encendido,
 * el lote se aplica en su transaccion y un reenvio identico responde exito
 * SIN reaplicar.
 */
final class LegacySyncController extends Controller
{
    public function __construct(
        private LegacySyncVerifier $verifier,
        private LegacyBatchApplier $applier,
        private Connection $connection,
    ) {}

    /**
     * Recibe el lote del terminal legacy.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. El INTERRUPTOR MAESTRO. Apagado, el maestro no acepta lotes:
        //    la puerta esta antes de todo lo demas.
        if (! (bool) config('sync.enabled', false)) {
            return $this->responder('error', 'Synchronization is disabled on this server', 'invalid', 403);
        }

        // 2. El TRAFICO. El lote lleva datos de venta y viaja firmado, y el
        //    legacy filtra el TRANSPORTE antes de autenticar: una peticion
        //    que llega por HTTP plano, desde loopback o desde una IP que no
        //    esta admitida se rechaza sin llegar siquiera a la firma.
        if ($this->esLoopback($request->ip()) && ! (bool) config('sync.allow_localhost', false)) {
            Log::warning('sync: trafico desde localhost no permitido', [
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'Invalid Action', 'invalid', 403);
        }

        if ((bool) config('sync.require_https', true) && ! $request->isSecure()) {
            Log::warning('sync: peticion sin HTTPS', [
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'HTTPS is required', 'invalid', 403);
        }

        $allowedIps = array_map('strval', (array) config('sync.allowed_ips', []));

        if ($allowedIps !== [] && ! in_array((string) $request->ip(), $allowedIps, true)) {
            Log::warning('sync: IP no permitida', [
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'Invalid Action', 'invalid', 403);
        }

        // 3. El CUERPO CRUDO. El `data` viaja como un string JSON dentro
        //    del cuerpo: hay que leer los bytes, no el array.
        $rawBody = $request->getContent();

        if (trim($rawBody) === '') {
            return $this->responder('error', 'Invalid Action', 'invalid', 400);
        }

        // 4. El TAMANO se mide sobre el cuerpo COMPLETO: el terminal manda
        //    el lote entero en un solo POST.
        $maxPayload = (int) config('sync.max_payload', 2097152);

        if (strlen($rawBody) > $maxPayload) {
            Log::warning('sync: cuerpo demasiado grande', [
                'bytes' => strlen($rawBody),
                'maximo' => $maxPayload,
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'Payload too large', 'invalid', 413);
        }

        // 5. El cuerpo tiene que ser un OBJETO JSON.
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return $this->responder('error', 'Invalid Action', 'invalid', 400);
        }

        // 6. La ACCION: el legacy solo admite `sync`.
        if ($this->texto($payload['action'] ?? '') !== 'sync') {
            return $this->responder('error', 'Invalid Action', 'invalid', 400);
        }

        // 7. El LOTE: el `data` es un string JSON, asi que hay que
        //    decodificarlo OTRA vez. El string se guarda tal cual: son los
        //    bytes que se firmaron, y son los que identifican el lote.
        $data = $this->texto($payload['data'] ?? '');

        $lote = json_decode($data, true);

        if (! is_array($lote) || $lote === []) {
            return $this->responder('error', 'Nothing to sync', 'invalid', 400);
        }

        // 8. El NUMERO de sentencias: un lote enorme es una llamada que
        //    puede tumbar el maestro.
        $maxStatements = (int) config('sync.max_statements', 500);

        if (count($lote) > $maxStatements) {
            Log::warning('sync: demasiadas sentencias en el lote', [
                'sentencias' => count($lote),
                'maximo' => $maxStatements,
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'Too many statements', 'invalid', 413);
        }

        // 9. La AUTENTICACION. Aqui se decide si el lote entra, y el
        //    motivo interno (firma rota, cliente desconocido, nonce
        //    repetido...) se queda en el log del servidor.
        $verificacion = $this->verifier->verify($payload, $rawBody);

        if (! $verificacion->ok) {
            Log::warning('sync: autenticacion fallida', [
                'reason' => $verificacion->reason,
                'ip' => $request->ip(),
            ]);

            return $this->responder('error', 'Invalid Action', 'invalid', 401);
        }

        // 10. La APLICACION del lote. El ADAPTADOR es el interruptor del
        //     modulo: apagado, el maestro no traduce ni aplica (el mapa de
        //     traduccion todavia no esta sembrado) y el terminal queda
        //     PAUSADO, no CONGELADO. Encendido, el lote se aplica en su
        //     transaccion: todo o nada.
        if ((bool) config('sync.legacy_adapter_enabled', false)) {
            try {
                $this->applier->apply(
                    (string) $verificacion->client,
                    $data,
                    $this->contextoDelLote($verificacion->client),
                );
            } catch (Throwable $e) {
                // El motivo INTERNO (una columna que no existe, un filtro
                // que el maestro no sabe calcular, una restriccion del
                // esquema) va al log del servidor: el terminal solo ve
                // `Sync failed`.
                Log::error('sync: el lote no se pudo aplicar', [
                    'client' => $verificacion->client,
                    'error' => $e->getMessage(),
                    'ip' => $request->ip(),
                ]);

                return $this->responder('error', 'Sync failed', 'sync', 500);
            }
        }

        // 11. LA RESPUESTA. Es la del legacy: tres claves y su codigo.
        return $this->responder('success', 'sync successfully done', 'sync', 200);
    }

    /**
     * El CONTEXTO del lote: la TIENDA del cliente autenticado y su FECHA DE
     * NEGOCIO.
     *
     * La tienda sale del CLIENTE (la configuracion `sync.client_stores`),
     * nunca del cuerpo del lote: un terminal no escribe en la tienda que no
     * es suya, y el maestro no va a preguntarle al lote.
     *
     * La fecha de negocio es el DATO de la caja ABIERTA de esa tienda, no
     * un `now()` calculado: el legacy partia el dia a la medianoche del
     * servidor, y una operacion de las 23:50 caia en el dia que le tocaba
     * (trampa 62 del mapa funcional). Si no hay caja abierta, el contexto
     * no trae fecha: las operaciones que la necesiten se rechazan, en vez
     * de inventar una.
     *
     * @return array<string, mixed>
     */
    private function contextoDelLote(?string $clientId): array
    {
        $contexto = [];

        $storeId = $this->tiendaDelCliente($clientId);

        if ($storeId === null) {
            Log::warning('sync: el cliente no tiene tienda asignada', [
                'client' => $clientId,
            ]);

            return $contexto;
        }

        $contexto['store_id'] = $storeId;

        $fecha = $this->fechaDeNegocio($storeId);

        if ($fecha !== null) {
            $contexto['business_date'] = $fecha;
        }

        return $contexto;
    }

    /**
     * La TIENDA del cliente autenticado, o `null` si no tiene ninguna.
     */
    private function tiendaDelCliente(?string $clientId): ?int
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $tiendas = (array) config('sync.client_stores', []);
        $valor = $tiendas[$clientId] ?? null;

        if ($valor === null || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * La FECHA DE NEGOCIO de la caja abierta de la tienda.
     */
    private function fechaDeNegocio(int $storeId): ?string
    {
        $registro = $this->connection->table('cash_registers')
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->orderByDesc('business_date')
            ->first();

        if ($registro === null) {
            return null;
        }

        return (string) $registro->business_date;
    }

    /**
     * Si la IP remota es de LOOPBACK.
     *
     * Es la regla del legacy (`sync_is_localhost`): `::1` o el rango
     * `127.0.0.0/8`. El terminal que sincroniza es una sucursal, no la
     * maquina del maestro.
     */
    private function esLoopback(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    /**
     * La respuesta del sync: TRES claves y nada mas.
     *
     * El codigo HTTP va aparte porque el legacy lo elige segun el motivo,
     * pero el cuerpo es siempre el mismo.
     */
    private function responder(string $status, string $message, string $for, int $codigo): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'for' => $for,
        ], $codigo);
    }

    /**
     * Un valor de la peticion como texto.
     */
    private function texto(mixed $valor): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        if (is_scalar($valor)) {
            return (string) $valor;
        }

        return '';
    }
}
