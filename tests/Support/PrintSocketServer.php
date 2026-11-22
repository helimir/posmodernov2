<?php

namespace Tests\Support;

use RuntimeException;

/**
 * La IMPRESORA DE PRUEBA: un socket que escucha y se queda con los bytes que
 * le llegan.
 *
 * POR QUE EXISTE, Y POR QUE ESCUCHA EN EL PUERTO 9100:
 *
 * El destino de impresion declara su endpoint, y el CHECK del esquema
 * (`print_targets_endpoint_admitted`) admite `tcp://servidor:<puerto>`, la
 * impresora compartida de Windows o un recurso UNC. El puerto de la
 * impresora de red es el que tenga la impresora: el 9100 es el RAW de facto
 * del estandar ESC/POS, pero no lo es una ley. Por eso el socket de la
 * prueba escucha en ESE puerto, que es el que el estandar usa de hecho: si
 * escuchara en otro, la prueba apuntaria a un destino que ninguna
 * instalacion real tiene.
 *
 * Y POR QUE SIGUE SIENDO UN PUERTO FIJO: el socket de prueba es UNO, y dos
 * pruebas de impresion a la vez no pueden escuchar en el mismo puerto.
 *
 * QUE HACE:
 *
 *  - ESCUCHA en un puerto local y lo dice con `endpoint()`: el destino de
 *    impresion de la prueba se crea con esa direccion;
 *  - SE QUEDA con los bytes: `recibidos()` acepta la conexion y devuelve lo
 *    que llego, byte a byte. Si el ticket se escribiese DOS veces, los bytes
 *    volverian DOBLES, y la prueba lo veria.
 */
final class PrintSocketServer
{
    /**
     * El puerto RAW de las impresoras de red: el que admite el CHECK del
     * esquema de destinos.
     */
    public const PUERTO_RAW = 9100;

    /**
     * Cuanto se espera a que llegue la conexion, en segundos.
     */
    private const ESPERA_DE_CONEXION = 5;

    /**
     * Cuanto se espera a que llegue OTRA conexion, en segundos.
     *
     * Es corto a proposito: si el ticket se escribio una vez y solo una, no
     * hay mas conexiones, y la prueba no debe pagar una espera larga por
     * comprobarlo.
     */
    private const ESPERA_DE_OTRA = 1;

    /**
     * @param  resource  $socket  El socket que escucha.
     */
    private function __construct(
        private $socket,
        private readonly string $host,
        private readonly int $port,
    ) {}

    /**
     * Levanta el socket de prueba.
     *
     * @throws RuntimeException Si el puerto esta ocupado.
     */
    public static function start(string $host = '127.0.0.1', int $port = self::PUERTO_RAW): self
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException(
                "No se pudo abrir el socket de prueba en {$host}:{$port}: ".($errstr !== '' ? $errstr : "error {$errno}").'. '
                .'El puerto '.self::PUERTO_RAW.' es el RAW de facto del estandar ESC/POS: '
                .'si esta ocupado, libralo y vuelve a lanzar la prueba.'
            );
        }

        return new self($socket, $host, $port);
    }

    /**
     * La DIRECCION del socket: la que declara el destino de impresion.
     */
    public function endpoint(): string
    {
        return "tcp://{$this->host}:{$this->port}";
    }

    /**
     * Los BYTES que llegaron, en el orden en que llegaron.
     *
     * Acepta TODAS las conexiones que haya: si el ticket se escribio dos
     * veces, los bytes volverian dos veces, y la prueba lo ve.
     */
    public function recibidos(): string
    {
        $bytes = '';
        $primera = true;

        while (true) {
            $conexion = @stream_socket_accept(
                $this->socket,
                $primera ? self::ESPERA_DE_CONEXION : self::ESPERA_DE_OTRA
            );

            if ($conexion === false) {
                break;
            }

            $primera = false;

            stream_set_timeout($conexion, self::ESPERA_DE_CONEXION);

            while (! feof($conexion)) {
                $trozo = fread($conexion, 8192);

                if ($trozo === false || $trozo === '') {
                    break;
                }

                $bytes .= $trozo;
            }

            fclose($conexion);
        }

        return $bytes;
    }

    /**
     * Apaga el socket de prueba.
     */
    public function stop(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
