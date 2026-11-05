<?php

namespace App\Http\Controllers;

use App\Sync\Legacy\LegacySyncVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
 * | lote autenticado                | 200    | `sync successfully done`                      |
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
 */
final class LegacySyncController extends Controller
{
    public function __construct(private LegacySyncVerifier $verifier) {}

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
        //    decodificarlo OTRA vez.
        $lote = json_decode($this->texto($payload['data'] ?? ''), true);

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

        // 10. La RESPUESTA. En la unidad 8-iv-c, aqui se aplica el lote en
        //     una transaccion y se registra en `sync_batches`.
        return $this->responder('success', 'sync successfully done', 'sync', 200);
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
