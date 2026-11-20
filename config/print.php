<?php

return [

    /*
     * El interruptor del canal del agente LAN. Apagado hasta que los destinos
     * y la rotacion del token del agente esten validados: la impresion nunca
     * depende de la emision fiscal, ni al reves.
     */
    'lan_agent_enabled' => env('PRINT_LAN_AGENT_ENABLED', false),

    /*
     * Cuanto tiempo puede pasar un agente sin latir antes de que se le
     * considere desconectado.
     *
     * Esto es un PARAMETRO y no una columna: cambia con la operacion (el
     * intervalo del latido), no con el esquema. Un agente esta en linea
     * mientras `now() - last_heartbeat_at` este por debajo de este umbral. El
     * defecto son 60 segundos, mas o menos tres intervalos de latido.
     */
    'agent_disconnect_threshold_seconds' => (int) env('PRINT_AGENT_DISCONNECT_THRESHOLD_SECONDS', 60),

    /*
     * Cuanto tiempo espera la PRUEBA de conexion a que la impresora conteste.
     *
     * Esto es un PARAMETRO y no una columna: cambia con la red, no con el
     * esquema. La prueba solo abre la conexion y la vuelve a cerrar, asi que
     * no necesita el margen del escritor (que es el de un ticket entero):
     * necesita uno corto, porque quien espera la respuesta esta delante de
     * la pantalla.
     */
    'connection_test_timeout_seconds' => (int) env('PRINT_CONNECTION_TEST_TIMEOUT_SECONDS', 2),

    /*
     * Si el canal del agente EXIGE TLS.
     *
     * El token del agente es la credencial de una caja y el trabajo lleva
     * DATOS FISCALES: sin cifrar, cualquiera que espie la LAN tiene lo que
     * el agente tiene. Por defecto esta ENCENDIDO.
     *
     * Apagarlo (`PRINT_REQUIRE_TLS=false`) es una decision EXPLICITA del
     * operador, para una LAN que ya no sale de la tienda.
     */
    'require_tls' => env('PRINT_REQUIRE_TLS', true),

    /*
     * Los ENTORNOS donde el TLS no se exige: el desarrollo y las pruebas.
     *
     * En `local` y en `testing` no hay TLS, asi que exigirlo dejaria todo
     * roto sin ganar nada. Fuera de ahi, el TLS se exige.
     */
    'tls_exempt_environments' => ['local', 'testing'],

    /*
     * Si se CREE en la cabecera `X-Forwarded-Proto` que dice si la peticion
     * original venia por HTTPS.
     *
     * Solo se mira cuando el TLS termina en un PROXY de confianza (Apache,
     * Nginx, un balanceador). Por defecto NO se mira: si se mirara sin proxy
     * de por medio, cualquiera podria fingir que su peticion en claro es
     * HTTPS, y el token volveria a viajar sin cifrar.
     */
    'trust_forwarded_proto' => env('PRINT_TRUST_FORWARDED_PROTO', false),

    /*
     * La VENTANA DE GRACIA de la rotacion del token, en segundos.
     *
     * Al rotar, el token ANTERIOR sigue valiendo este rato: el token nuevo
     * tarda en llegarle al agente, y sin esta ventana la caja se queda muda
     * entre la rotacion y la entrega.
     *
     * Es un PARAMETRO y no una columna: es una decision de operacion, no de
     * esquema. El defecto son 300 segundos (cinco minutos).
     */
    'token_grace_period_seconds' => (int) env('PRINT_AGENT_TOKEN_GRACE_SECONDS', 300),

];
