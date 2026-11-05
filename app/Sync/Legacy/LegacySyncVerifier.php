<?php

namespace App\Sync\Legacy;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El VERIFICADOR del contrato de wire v2 del sync legacy.
 *
 * REGLA que fija esta clase: el ORDEN de verificacion es el del legacy y el
 * MOTIVO INTERNO nunca sale al cliente.
 *
 * El legacy (`_inc/helper/sync.php`) verifica en este orden:
 *
 *   1. el cliente es conocido (`SYNC_CLIENTS`),
 *   2. el `timestamp` tiene la forma `^\d{10}$`,
 *   3. la antiguedad no supera `SYNC_MAX_AGE`,
 *   4. la firma coincide (`hash_equals`),
 *   5. y SOLO ENTONCES se consume el nonce.
 *
 * El paso 5 va al final a proposito: una peticion con la firma rota NO
 * quema el nonce, para que un terminal no autenticado no pueda agotar los
 * nonces de los demas (el escenario "Nonce no consumido por firma invalida"
 * de la especificacion).
 *
 * El consumo es ATOMICO: Redis `SET NX EX`, el mismo contrato que el
 * `fopen(..., 'x')` del legacy, y el nonce se poda solo por antiguedad. Si
 * Redis no esta, se falla CERRADO: sin almacen de nonces no hay
 * anti-replay, y sin anti-replay no se acepta nada.
 *
 * El CANONIZADO es el del legacy, sin tocar:
 *
 *   username \n action \n timestamp \n nonce \n sha256(data)
 *
 * con el hash sobre los bytes EXACTOS del string `data`: el lote NUNCA se
 * re-codifica para firmarlo.
 */
final readonly class LegacySyncVerifier
{
    public function __construct(
        private Connection $connection,
        private RedisFactory $redis,
    ) {}

    /**
     * Verifica una peticion del terminal legacy.
     *
     * @param  array<string, mixed>  $payload  El cuerpo ya decodificado.
     * @param  string  $rawBody  El cuerpo CRUDO de la peticion.
     * @return LegacyVerification El resultado y la respuesta que le toca.
     */
    public function verify(array $payload, string $rawBody): LegacyVerification
    {
        $username = $this->texto($payload['username'] ?? '');
        $action = $this->texto($payload['action'] ?? '');
        $data = $this->dataDelLote($payload, $rawBody);
        $timestamp = $this->texto($payload['timestamp'] ?? '');
        $nonce = $this->texto($payload['nonce'] ?? '');
        $signature = $this->texto($payload['signature'] ?? '');

        // La RUTA FIRMADA (v2) es la que trae las tres piezas. Si falta
        // CUALQUIERA de ellas no hay nada firmado que verificar.
        if ($timestamp !== '' && $nonce !== '' && $signature !== '') {
            return $this->verificarRutaFirmada(
                $username,
                $action,
                $data,
                $timestamp,
                $nonce,
                $signature,
            );
        }

        // La RUTA HEREDADA (v1), solo durante la transicion.
        return $this->verificarRutaHeredada($username, $payload);
    }

    /**
     * La RUTA FIRMADA (v2), en el orden del legacy.
     */
    private function verificarRutaFirmada(
        string $username,
        string $action,
        string $data,
        string $timestamp,
        string $nonce,
        string $signature,
    ): LegacyVerification {
        $clients = (array) config('sync.clients', []);

        // 1. El cliente tiene que ser CONOCIDO: sin secreto con que
        //    comparar la firma, no hay nada que hacer.
        if (! isset($clients[$username]) || ! is_string($clients[$username]) || $clients[$username] === '') {
            return LegacyVerification::rechazado('unknown_client');
        }

        // 2. El `timestamp` tiene que tener la FORMA de una epoch en
        //    segundos: diez digitos.
        if (preg_match('/^\d{10}$/', $timestamp) !== 1) {
            return LegacyVerification::rechazado('bad_timestamp');
        }

        // 3. La ANTIGUEDAD no puede superar el margen (`SYNC_MAX_AGE`): una
        //    firma capturada no sirve para siempre.
        $maxAge = (int) config('sync.max_age', 300);

        if (abs(time() - (int) $timestamp) > $maxAge) {
            return LegacyVerification::rechazado('expired_request');
        }

        // 4. La FIRMA: el canonizado es el del legacy, con el hash sobre los
        //    bytes exactos del string `data`.
        $esperada = hash_hmac(
            'sha256',
            $this->canonizado($username, $action, $timestamp, $nonce, $data),
            (string) $clients[$username],
        );

        if (! hash_equals($esperada, $signature)) {
            return LegacyVerification::rechazado('bad_signature');
        }

        // 5. El NONCE se consume SOLO DESPUES de validar la firma.
        $motivo = $this->consumirNonce($nonce, $maxAge);

        if ($motivo !== null) {
            return LegacyVerification::rechazado($motivo);
        }

        // Un REENVIO IDENTICO del mismo lote ya aplicado se responde con
        // EXITO: el terminal trunca su log solo con la respuesta exitosa,
        // asi que el reenvio tiene que ser un exito o el terminal se
        // queda congelado. La clave es (`client_id`, `payload_sha256`), y
        // el hash es del LOTE, no del cuerpo HTTP: el terminal reenvia el
        // mismo lote con un nonce y un timestamp nuevos.
        if ($this->loteYaAplicado($username, hash('sha256', $data))) {
            return LegacyVerification::aceptado($username, 'signed', 'already_applied');
        }

        return LegacyVerification::aceptado($username, 'signed');
    }

    /**
     * La RUTA HEREDADA (v1): usuario y contrasena planos, sin firma.
     *
     * Solo mientras `SYNC_LEGACY_AUTH` este encendido, para que los
     * terminales que aun no firman no se queden fuera. `SYNC_LEGACY_PREVIOUS`
     * acepta la credencial que se esta rotando.
     */
    private function verificarRutaHeredada(string $username, array $payload): LegacyVerification
    {
        if (! (bool) config('sync.legacy_auth', true)) {
            return LegacyVerification::rechazado('signature_required');
        }

        $usuario = (string) config('sync.legacy_username', '');
        $contrasena = (string) config('sync.legacy_password', '');

        if ($usuario === '' || $contrasena === '') {
            return LegacyVerification::rechazado('legacy_auth_not_configured');
        }

        $password = $this->texto($payload['password'] ?? '');

        // La credencial VIGENTE.
        if (hash_equals($usuario, $username) && hash_equals($contrasena, $password)) {
            return LegacyVerification::aceptado($username, 'legacy');
        }

        // Las credenciales PREVIAS: se aceptan solo durante la ventana de
        // rotacion.
        $anteriores = (array) config('sync.legacy_previous', []);

        if (isset($anteriores[$username])
            && is_string($anteriores[$username])
            && hash_equals($anteriores[$username], $password)) {
            return LegacyVerification::aceptado($username, 'legacy-previous');
        }

        return LegacyVerification::rechazado('bad_credentials');
    }

    /**
     * Consume el nonce: la creacion es ATOMICA y el nonce se poda solo.
     *
     * `SET NX EX` es el mismo contrato que el `fopen(..., 'x')` del legacy:
     * si el nonce ya existe, otra peticion llego primero. El TTL es
     * `max_age * 3`, el margen con el que el legacy podaba sus ficheros.
     *
     * @return string|null El motivo del rechazo, o `null` si el nonce se consumio.
     */
    private function consumirNonce(string $nonce, int $maxAge): ?string
    {
        $nonce = preg_replace('/[^a-f0-9]/i', '', $nonce) ?? '';

        if ($nonce === '') {
            return 'replayed_request';
        }

        $conexion = $this->redis->connection((string) config('sync.redis_connection', 'default'));

        try {
            $resultado = $conexion->set(
                'sync:nonce:'.$nonce,
                (string) time(),
                'EX',
                max(1, $maxAge) * 3,
                'NX',
            );
        } catch (Throwable $e) {
            // Sin Redis NO hay anti-replay: se falla CERRADO. Aceptar el lote
            // seria abrir la puerta a que una peticion capturada se
            // reenvie para siempre.
            Log::error('sync: el almacen de nonces no esta disponible', [
                'error' => $e->getMessage(),
            ]);

            return 'nonce_store_unavailable';
        }

        // `true` con phpredis, `OK` con predis, y `null` cuando el nonce ya
        // existia.
        if ($resultado === true || (string) $resultado === 'OK') {
            return null;
        }

        return 'replayed_request';
    }

    /**
     * Si el LOTE ya se aplico antes para ese cliente.
     *
     * La fila de `sync_batches` es la traza: su restriccion unica es lo que
     * hace idempotente al maestro.
     */
    private function loteYaAplicado(string $clientId, string $payloadSha256): bool
    {
        return $this->connection->table('sync_batches')
            ->where('client_id', $clientId)
            ->where('payload_sha256', $payloadSha256)
            ->exists();
    }

    /**
     * El CANONIZADO del legacy (`sync_canonical`).
     *
     * Son CINCO campos y un separador LF, en este orden: `username`,
     * `action`, `timestamp`, `nonce` y el hash SHA-256 del string `data`.
     * El `timestamp` entra COMO TEXTO y el hash es sobre los bytes exactos
     * del `data`.
     */
    private function canonizado(
        string $username,
        string $action,
        string $timestamp,
        string $nonce,
        string $data,
    ): string {
        return implode("\n", [
            $username,
            $action,
            $timestamp,
            $nonce,
            hash('sha256', $data),
        ]);
    }

    /**
     * El `data` del LOTE: el string que viaja y que se firma.
     *
     * En el protocolo el `data` es un STRING JSON (la doble codificacion
     * del legacy): lo que se firma son sus bytes exactos. Se lee del cuerpo
     * crudo cuando el array decodificado no lo trae como texto, para que
     * una re-codificacion del array no pueda cambiar lo que se firma.
     */
    private function dataDelLote(array $payload, string $rawBody): string
    {
        if (isset($payload['data']) && is_string($payload['data'])) {
            return $payload['data'];
        }

        $crudo = json_decode($rawBody, true);

        if (is_array($crudo) && isset($crudo['data']) && is_string($crudo['data'])) {
            return $crudo['data'];
        }

        return '';
    }

    /**
     * Un valor de la peticion como texto.
     *
     * El legacy hacia `(string) $post['clave']`. Un valor que no es texto
     * (un array, un `null`) se trata como cadena vacia: el canonizado se
     * firma sobre el string `data`, y un `data` que no es texto no es un
     * lote.
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
