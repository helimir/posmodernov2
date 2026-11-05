<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Los datos del sync legacy para las pruebas: el lote firmado con el
 * canonizado DEL LEGACY y el almacen de nonces.
 *
 * El canonizado que se usa aqui es el de `_inc/helper/sync.php`:
 *
 *   username \n action \n timestamp \n nonce \n sha256(data)
 *
 * con el hash SHA-256 sobre los bytes EXACTOS del string `data` (el lote
 * viaja como un string JSON dentro del cuerpo: la doble codificacion del
 * legacy). Si el canonizado cambia, el terminal legitimo deja de entrar, y
 * el test tiene que notarlo.
 */
final class SyncFixture
{
    /**
     * El CLIENTE y su SECRETO, tal como se configuran en `SYNC_CLIENTS`.
     */
    public const CLIENTE = 'pos-01';

    public const SECRETO = 'secreto-dorado';

    /**
     * Un LOTE firmado con el canonizado del legacy.
     *
     * @param  array<int, array<string, mixed>>  $sentencias  El lote: una lista de `{sql, args}`.
     * @param  array<string, mixed>  $sobrescritura  Campos a forzar (`action`, `data`, `timestamp`, `nonce`, `signature`).
     * @return array{body: string, payload: array<string, mixed>, canonical: string}
     */
    public static function loteFirmado(
        string $cliente = self::CLIENTE,
        string $secreto = self::SECRETO,
        array $sentencias = [],
        array $sobrescritura = [],
    ): array {
        // El `data` es el STRING JSON del lote: el hash de la firma se
        // calcula sobre sus bytes exactos, nunca sobre un array
        // re-codificado.
        $data = array_key_exists('data', $sobrescritura)
            ? (string) $sobrescritura['data']
            : (string) json_encode($sentencias);

        $accion = (string) ($sobrescritura['action'] ?? 'sync');

        $timestamp = array_key_exists('timestamp', $sobrescritura)
            ? (int) $sobrescritura['timestamp']
            : time();

        $nonce = (string) ($sobrescritura['nonce'] ?? bin2hex(random_bytes(16)));

        // El CANONIZADO del legacy: cinco campos y un separador LF.
        $canonical = implode("\n", [
            $cliente,
            $accion,
            (string) $timestamp,
            $nonce,
            hash('sha256', $data),
        ]);

        $firma = array_key_exists('signature', $sobrescritura)
            ? (string) $sobrescritura['signature']
            : hash_hmac('sha256', $canonical, $secreto);

        $payload = array_merge([
            'username' => $cliente,
            'action' => $accion,
            'data' => $data,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $firma,
        ], $sobrescritura);

        return [
            'body' => (string) json_encode($payload),
            'payload' => $payload,
            'canonical' => $canonical,
        ];
    }

    /**
     * El lote de ejemplo: una sentencia de venta del terminal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sentencias(): array
    {
        return [
            [
                'sql' => 'INSERT INTO selling_info (invoice_number, store_id) VALUES (?, ?)',
                'args' => ['V-0001', 1],
            ],
        ];
    }

    /**
     * El almacen de NONCES tiene que estar disponible.
     *
     * Sin Redis NO hay anti-replay: el verificador falla CERRADO y rechaza
     * el lote. Un test que se salta cuando Redis no esta no es una
     * garantia, asi que el test FALLA con un mensaje claro.
     *
     * @throws RuntimeException Cuando Redis no esta disponible.
     */
    public static function exigirRedis(): void
    {
        try {
            Redis::connection((string) config('sync.redis_connection', 'default'))->ping();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'El sync legacy necesita Redis para los nonces y Redis NO esta disponible: '
                .'levanta el servicio (`docker compose up -d redis`) o el test no puede '
                .'demostrar el anti-replay. Detalle: '.$e->getMessage(),
                0,
                $e
            );
        }
    }
}
