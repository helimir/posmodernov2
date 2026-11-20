<?php

namespace App\Print;

use App\Models\PrintTarget;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La PRUEBA de la conexion de un destino de impresion.
 *
 * REGLA que fija esta clase: *quien puede escribir en un destino lo decide
 * el TRANSPORTE, y el que conecta tambien*.
 *
 *  - `server_socket`: el SERVIDOR abre el socket, comprueba que la
 *    impresora acepta la conexion y lo CIERRA. No escribe un ticket: aqui se
 *    prueba, no se imprime. El tiempo de espera es CORTO, y no el del
 *    escritor: el del escritor es el de un ticket entero, y aqui solo se
 *    quiere saber si hay alguien escuchando;
 *  - `lan_agent`: NO se puede probar desde el servidor, porque el que
 *    conecta es el agente. La respuesta lo dice, con el motivo;
 *  - `browser`: NO APLICA, porque no hay destino fisico: el navegador es el
 *    destino.
 *
 * Y ADEMAS SE AVISA DEL ANCHO INSUFICIENTE: con el ancho declarado y el
 * nombre de la impresora hay lineas que no caben, y eso es el aviso temprano
 * del "ticket mudo": la conexion puede estar bien y el ticket no caber.
 */
final readonly class PrintConnectionTester
{
    /**
     * El tiempo de espera POR DEFECTO de la prueba, en segundos.
     *
     * Es CORTO a proposito: el del escritor es el de un ticket entero, y
     * aqui solo se quiere saber si hay alguien escuchando.
     */
    private const TIEMPO_DE_CONEXION = 2;

    public function __construct(private Connection $connection) {}

    /**
     * La PRUEBA de la conexion de un destino.
     *
     * NUNCA lanza por un destino que no conecta: la conexion que no se abre
     * es un RESULTADO con su motivo, no un error del servidor.
     */
    public function probar(PrintTarget $target): PrintConnectionReport
    {
        $transporte = PrintTransport::desde((string) $target->transport);

        $avisos = $this->avisosDelAncho($target);

        // 1. El NAVEGADOR: no hay destino fisico, y no hay conexion que probar.
        if ($transporte === PrintTransport::Browser) {
            return new PrintConnectionReport(
                PrintConnectionReport::NO_APLICA,
                'El transporte «browser» no es un destino fisico: no hay conexion que probar. '
                .'El comprobante se sirve en el navegador.',
                null,
                $avisos,
            );
        }

        // 2. El AGENTE: el que conecta es el agente, no el servidor.
        if ($transporte === PrintTransport::LanAgent) {
            return new PrintConnectionReport(
                PrintConnectionReport::NO_PROBABLE,
                'El destino lo escribe el agente LAN: el servidor no puede probar la conexion desde aqui, '
                .'porque el que conecta es el agente.',
                null,
                $avisos,
            );
        }

        // 3. El SERVIDOR: se abre el socket y se cierra. No se escribe nada.
        return $this->pruebaElSocket($target, $avisos);
    }

    /**
     * La PRUEBA del socket: se abre, se comprueba y se cierra.
     *
     * @param  list<string>  $avisos  Los avisos que ya se saben.
     */
    private function pruebaElSocket(PrintTarget $target, array $avisos): PrintConnectionReport
    {
        try {
            $destino = PrintEndpoint::deTexto($target->endpoint);
        } catch (RuntimeException $fallo) {
            return new PrintConnectionReport(
                PrintConnectionReport::FALLIDA,
                'No se pudo conectar: '.$fallo->getMessage(),
                $fallo->getMessage(),
                $avisos,
            );
        }

        if (! $destino->esSocket()) {
            // La cola compartida de Windows no se abre por TCP: se abre el
            // recurso, y eso lo hace el escritor cuando escribe el ticket.
            return new PrintConnectionReport(
                PrintConnectionReport::NO_PROBABLE,
                'El destino es una impresora compartida de Windows: la conexion no se prueba por red, '
                .'sino abriendo la cola de impresion cuando se escribe el ticket.',
                null,
                $avisos,
            );
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $destino->direccionDeSocket(),
            $errno,
            $errstr,
            $this->tiempoDeConexion(),
        );

        if ($socket === false) {
            $motivo = $errstr !== '' ? $errstr : "error {$errno}";

            return new PrintConnectionReport(
                PrintConnectionReport::FALLIDA,
                'No se pudo conectar: '.$motivo.'.',
                $motivo,
                $avisos,
            );
        }

        // La conexion se CIERRA: aqui se prueba, no se imprime. Un ticket
        // escrito en una prueba es un ticket que el cajero no pidio.
        fclose($socket);

        return new PrintConnectionReport(
            PrintConnectionReport::CONECTADA,
            'La impresora respondio.',
            null,
            $avisos,
        );
    }

    /**
     * Los AVISOS del ancho: el aviso temprano del ticket mudo.
     *
     * La conexion puede estar en su sitio y el ticket no caber: con el ancho
     * declarado y el nombre de la impresora hay lineas que no caben, y eso se
     * dice aqui, antes de que el cajero cobre.
     *
     * @return list<string>
     */
    private function avisosDelAncho(PrintTarget $target): array
    {
        $ancho = $target->char_per_line === null ? null : (int) $target->char_per_line;

        if ($ancho === null || $ancho < PrintWidth::ANCHO_MINIMO) {
            return [
                'El destino no declara el ancho de linea: sin el no se puede comprobar si el ticket cabe.',
            ];
        }

        $nombre = $this->nombreDeLaImpresora((int) $target->printer_id);

        if ($nombre === '' || ! PrintWidth::excede($nombre, $ancho)) {
            return [];
        }

        return [
            'Con '.$ancho.' caracteres por linea, el nombre de la impresora «'.$nombre.'» no cabe: '
            .'mide '.mb_strlen($nombre).' caracteres.',
        ];
    }

    /**
     * El NOMBRE de la impresora del catalogo.
     */
    private function nombreDeLaImpresora(int $printerId): string
    {
        $nombre = $this->connection->table('printers')
            ->where('id', $printerId)
            ->value('name');

        return $nombre === null ? '' : (string) $nombre;
    }

    /**
     * El TIEMPO de espera de la prueba, en segundos.
     */
    private function tiempoDeConexion(): int
    {
        $segundos = (int) config('print.connection_test_timeout_seconds', self::TIEMPO_DE_CONEXION);

        return $segundos > 0 ? $segundos : self::TIEMPO_DE_CONEXION;
    }
}
