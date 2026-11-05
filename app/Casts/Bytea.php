<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * El `bytea` de PostgreSQL.
 *
 * PDO devuelve la columna `bytea` como un RECURSO (un flujo), no como una
 * cadena. Un cast `string` de Eloquent haría `(string) $recurso` y devolvería
 * `"Resource id #525"`: los bytes del ticket se convertirían en texto
 * basura, en silencio.
 *
 * Este cast devuelve la cadena REAL de bytes, y escribe el valor como
 * hexadecimal (`decode(..., 'hex')`) porque el constructor de consultas
 * convierte cualquier cadena con un byte nulo en un flujo, y el flujo se
 * inserta como su descripción textual: los bytes se truncan.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Bytea implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            if (get_resource_type($value) !== 'stream') {
                return null;
            }

            // El flujo se REBOBINA antes de leer. Sin esto, la segunda
            // lectura del atributo devolvería una cadena vacía: los bytes
            // del ticket se perderían en silencio la segunda vez que se
            // tocan.
            if (stream_get_meta_data($value)['seekable'] ?? false) {
                rewind($value);
            }

            $contenido = stream_get_contents($value);

            return $contenido === false ? null : $contenido;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        // El hexadecimal sólo contiene [0-9a-f]: no hay forma de que altere
        // la sentencia.
        return DB::raw("decode('".bin2hex((string) $value)."', 'hex')");
    }
}
