<?php

/*
 * El mapa de pares "clave:valor" del entorno.
 *
 * El formato es el del legacy (`SYNC_CLIENTS="pos-01:secret1,pos-02:secret2"`):
 * el par se parte por la PRIMERA coma y el PRIMER dos puntos, de modo que el
 * secreto puede llevar dos puntos dentro.
 */
$pares = static function (string $crudo): array {
    $mapa = [];

    foreach (explode(',', $crudo) as $par) {
        $par = trim($par);

        if ($par === '' || ! str_contains($par, ':')) {
            continue;
        }

        [$clave, $valor] = explode(':', $par, 2);

        $clave = trim($clave);
        $valor = trim($valor);

        if ($clave !== '' && $valor !== '') {
            $mapa[$clave] = $valor;
        }
    }

    return $mapa;
};

/*
 * La lista simple del entorno: "a,b,c" -> ["a", "b", "c"].
 */
$lista = static function (string $crudo): array {
    $valores = [];

    foreach (explode(',', $crudo) as $valor) {
        $valor = trim($valor);

        if ($valor !== '') {
            $valores[] = $valor;
        }
    }

    return $valores;
};

return [

    /*
     * Legacy sync adapter switch. Off until the translation map is seeded and
     * audited: turning it off pauses the legacy terminals, it does not freeze
     * them.
     */
    'legacy_adapter_enabled' => env('SYNC_LEGACY_ADAPTER_ENABLED', false),

    /*
     * El INTERRUPTOR MAESTRO del protocolo. Apagado, el maestro NO acepta
     * lotes de los terminales legacy: responde 403. Es la puerta del todo,
     * y por eso esta apagada hasta que el mapa de traduccion este sembrado
     * y auditado.
     */
    'enabled' => env('SYNC_ENABLED', false),

    /*
     * Los CLIENTES conocidos del protocolo v2: `id => secreto`.
     *
     * El `id` es el `username` que viaja en el cuerpo, y tambien viaja
     * DENTRO de la firma: un cliente que no esta aqui no tiene con que
     * comparar su firma.
     */
    'clients' => $pares((string) env('SYNC_CLIENTS', '')),

    /*
     * La ANTIGUEDAD maxima de una peticion firmada, en segundos. Es el
     * `SYNC_MAX_AGE` del legacy: sin el, una firma capturada serviria para
     * siempre.
     */
    'max_age' => (int) env('SYNC_MAX_AGE', 300),

    /*
     * El TAMANO maximo del cuerpo de la peticion, en bytes. El limite se
     * mide sobre el cuerpo COMPLETO, porque el terminal manda el lote
     * entero en un solo POST.
     */
    'max_payload' => (int) env('SYNC_MAX_PAYLOAD', 2097152),

    /*
     * El NUMERO maximo de sentencias por lote. Un lote enorme es una
     * llamada que puede tumbar el maestro, asi que se acota antes de
     * aplicar nada.
     */
    'max_statements' => (int) env('SYNC_MAX_STATEMENTS', 500),

    /*
     * Si el protocolo exige HTTPS. El lote travels credenciales firmadas y
     * datos de venta: en claro es una fuga.
     */
    'require_https' => env('SYNC_REQUIRE_HTTPS', true),

    /*
     * Si se admite el trafico desde LOCALHOST. Apagado por defecto: el
     * terminal que sincroniza es una sucursal, no la maquina del maestro.
     */
    'allow_localhost' => env('SYNC_ALLOW_LOCALHOST', false),

    /*
     * Las IPs admitidas. Vacio significa "no se restringe por IP".
     */
    'allowed_ips' => $lista((string) env('SYNC_ALLOWED_IPS', '')),

    /*
     * La RUTA HEREDADA (v1): usuario y contrasena planos, sin firma.
     *
     * Solo durante la transicion: se apaga con `SYNC_LEGACY_AUTH=false`
     * cuando todos los terminales ya firman. Las credenciales de la
     * credencial vigente, y `legacy_previous` las que se estan rotando
     * (`user:secret,user2:secret2`).
     */
    'legacy_auth' => env('SYNC_LEGACY_AUTH', true),
    'legacy_username' => (string) env('SYNC_LEGACY_USERNAME', ''),
    'legacy_password' => (string) env('SYNC_LEGACY_PASSWORD', ''),
    'legacy_previous' => $pares((string) env('SYNC_LEGACY_PREVIOUS', '')),

    /*
     * La conexion de Redis donde viven los NONCES.
     *
     * El nonce se crea con `SET NX EX` (creacion atomica) y se poda solo
     * por antiguedad: el TTL es `max_age * 3`, que es el margen del
     * legacy para podar sus ficheros de nonce.
     */
    'redis_connection' => (string) env('REDIS_SYNC_CONNECTION', 'default'),

];
