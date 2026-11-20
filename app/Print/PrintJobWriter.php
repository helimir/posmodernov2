<?php

namespace App\Print;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * El ESCRITOR: el servicio que escribe los bytes CONGELADOS de un trabajo en
 * su destino.
 *
 * REGLA que fija esta clase: *el servidor escribe los bytes congelados del
 * trabajo en el destino, y quien puede escribir en un destino lo decide el
 * TRANSPORTE*.
 *
 *  - `server_socket`: los escribe el servidor, en el socket de la impresora de
 *    red o en la cola de la impresora compartida de Windows;
 *  - `lan_agent`: los escribe el agente, y el servidor SÓLO deja el trabajo
 *    encolado. Si el servidor los escribiera, el ticket saldría dos veces;
 *  - `browser`: el navegador es el destino. No hay destino físico, y el
 *    servidor no escribe bytes.
 *
 * Y LOS BYTES VAN EXACTAMENTE COMO SE CONGELARON: el `payload_sha256` del
 * trabajo es el hash de los bytes que se escriben, y el escritor lo
 * COMPRUEBA antes de escribir. Un trabajo cuyos bytes no son los que declara
 * su hash no se escribe: es corrupción, y un ticket corrupto es peor que un
 * ticket pendiente.
 *
 * Y EL FALLO DE LA IMPRESORA NO PIERDE LA VENTA: la venta ya está confirmada
 * y el ticket ya está encolado. Un trabajo que no se puede imprimir es un
 * trabajo `failed`, CON SU MOTIVO, y reintentable (`requeue`).
 *
 * Y EL RECLAMO ES EL DE LA MAQUINA DE ESTADOS: este servicio no escribe el
 * estado a mano. Dos bombeos a la vez no toman el mismo trabajo porque el
 * reclamo es ATOMICO (`UPDATE … WHERE status = 'queued'`), no porque este
 * servicio lo decida.
 */
final readonly class PrintJobWriter
{
    /**
     * El LEASE del trabajo que toma el servidor: si el servidor se muere en
     * mitad de la escritura, el trabajo vuelve a la cola.
     */
    private const LEASE_SEGUNDOS = 60;

    /**
     * Cuanto se espera a que la impresora acepte la conexión, en segundos.
     */
    private const TIEMPO_DE_CONEXION = 10;

    public function __construct(
        private Connection $connection,
        private PrintJobStateMachine $stateMachine,
    ) {}

    /**
     * Escribe los bytes de UN trabajo en su destino.
     *
     * NUNCA lanza por un fallo de la impresora: el fallo es un RESULTADO, y
     * el trabajo queda `failed` con su motivo. La venta ya está confirmada, y
     * confirmada es confirmada.
     *
     * @throws RuntimeException Si el trabajo no existe, o su destino tampoco.
     */
    public function escribir(int $jobId): PrintWriteOutcome
    {
        $trabajo = $this->connection->table('print_jobs')->where('id', $jobId)->first();

        if ($trabajo === null) {
            throw new RuntimeException("El trabajo de impresion {$jobId} no existe.");
        }

        $destino = $this->connection->table('print_targets')
            ->where('id', (int) $trabajo->print_target_id)
            ->first();

        if ($destino === null) {
            throw new RuntimeException(
                "El trabajo de impresion {$jobId} apunta a un destino de impresion que no existe."
            );
        }

        // 1. EL TRANSPORTE DECIDE QUIEN ESCRIBE. Y la respuesta la da la fila
        //    del destino, no el código que la usa: un destino del agente se
        //    deja encolado, y un destino del navegador no se escribe.
        $transporte = PrintTransport::desde((string) $destino->transport);

        if (! $transporte->loEscribeElServidor()) {
            return $transporte === PrintTransport::LanAgent
                ? PrintWriteOutcome::omitido(
                    $jobId,
                    PrintWriteOutcome::MOTIVO_AGENTE,
                    'El destino lo escribe el agente LAN: el servidor no escribe en el, o el ticket saldría dos veces.'
                )
                : PrintWriteOutcome::omitido(
                    $jobId,
                    PrintWriteOutcome::MOTIVO_NAVEGADOR,
                    'El navegador no es un destino fisico: no hay bytes que escribir.'
                );
        }

        // 2. EL TRABAJO TIENE QUE ESTAR ENCOLADO. Un trabajo ya reclamado o
        //    ya impreso no se vuelve a escribir: el papel no se duplica.
        if ((string) $trabajo->status !== 'queued') {
            return PrintWriteOutcome::omitido(
                $jobId,
                PrintWriteOutcome::MOTIVO_NO_ENCOLADO,
                "El trabajo de impresion {$jobId} no esta encolado (esta en «{$trabajo->status}»): no se escribe."
            );
        }

        // 3. EL RECLAMO ATOMICO, por la maquina de estados. Si otro bombeo lo
        //    toma primero, el reclamo falla y aqui se salta: el trabajo es de
        //    ese, no de este.
        try {
            $this->stateMachine->claimByServer($jobId, self::LEASE_SEGUNDOS);
        } catch (QueryException $error) {
            // La fila estaba bloqueada: otro bombeo la tiene en las manos.
            return PrintWriteOutcome::omitido(
                $jobId,
                PrintWriteOutcome::MOTIVO_BLOQUEADO,
                "No se pudo tomar el trabajo de impresion {$jobId}: ".$error->getMessage()
            );
        } catch (RuntimeException $error) {
            return PrintWriteOutcome::omitido(
                $jobId,
                PrintWriteOutcome::MOTIVO_TOMADO,
                $error->getMessage()
            );
        }

        // 4. LOS BYTES, y la escritura. A partir de aqui el trabajo esta
        //    en las manos del servidor: si algo falla, el trabajo es `failed`
        //    con su motivo, y reintentable.
        try {
            $bytes = $this->bytes($trabajo);

            $this->stateMachine->markPrinting($jobId);

            $this->escribirBytes((string) $destino->endpoint, $bytes);

            $this->stateMachine->markPrinted($jobId);
        } catch (Throwable $fallo) {
            $this->fallar($jobId, $fallo->getMessage());

            return PrintWriteOutcome::fallido($jobId, $fallo->getMessage());
        }

        return PrintWriteOutcome::escrito($jobId);
    }

    /**
     * Los BYTES CONGELADOS del trabajo, comprobados contra su hash.
     *
     * @throws RuntimeException Si el trabajo no tiene bytes, o si sus bytes no
     *                          son los que declara su hash.
     */
    private function bytes(object $trabajo): string
    {
        $jobId = (int) $trabajo->id;

        // El `bytea` se lee en hexadecimal: PDO devuelve la columna como un
        // flujo, y un flujo no son los bytes del ticket.
        $fila = $this->connection->selectOne(
            "SELECT encode(payload_bytes, 'hex') AS bytes FROM print_jobs WHERE id = ?",
            [$jobId]
        );

        $hexadecimal = $fila === null ? null : $fila->bytes;

        if ($hexadecimal === null) {
            throw new RuntimeException(
                "El trabajo de impresion {$jobId} no tiene bytes congelados: no hay ticket que escribir."
            );
        }

        $bytes = hex2bin((string) $hexadecimal);

        if ($bytes === false) {
            throw new RuntimeException(
                "Los bytes congelados del trabajo de impresion {$jobId} no se pueden leer: no se escribe nada."
            );
        }

        // EL HASH: los bytes que salen son EXACTAMENTE los del trabajo. Un
        // trabajo con los bytes cambiados es un trabajo corrupto, y un ticket
        // corrupto es peor que un ticket pendiente.
        $hash = (string) $trabajo->payload_sha256;

        if ($hash !== '' && ! hash_equals($hash, hash('sha256', $bytes))) {
            throw new RuntimeException(
                "Los bytes congelados del trabajo de impresion {$jobId} no son los que declara su hash: "
                .'no se escribe nada.'
            );
        }

        return $bytes;
    }

    /**
     * Escribe los bytes en el destino del endpoint.
     *
     * @throws RuntimeException Si el endpoint no está admitido, o si la
     *                          impresora no acepta los bytes.
     */
    private function escribirBytes(?string $endpoint, string $bytes): void
    {
        $destino = PrintEndpoint::deTexto($endpoint);

        if ($destino->esSocket()) {
            $this->escribirEnSocket($destino, $bytes);

            return;
        }

        $this->escribirEnCola($destino, $bytes);
    }

    /**
     * La IMPRESORA DE RED: un socket al puerto RAW.
     *
     * @throws RuntimeException Si no se puede conectar, o si no acepta todos
     *                          los bytes.
     */
    private function escribirEnSocket(PrintEndpoint $destino, string $bytes): void
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $destino->direccionDeSocket(),
            $errno,
            $errstr,
            self::TIEMPO_DE_CONEXION
        );

        if ($socket === false) {
            throw new RuntimeException(
                "No se pudo conectar con la impresora «{$destino->texto}»: "
                .($errstr !== '' ? $errstr : "error {$errno}").'.'
            );
        }

        try {
            $this->volcar($socket, $bytes, $destino->texto);
        } finally {
            fclose($socket);
        }
    }

    /**
     * La IMPRESORA COMPARTIDA de WINDOWS: la cola de impresión.
     *
     * @throws RuntimeException Si la cola no se puede abrir, o si no acepta
     *                          todos los bytes.
     */
    private function escribirEnCola(PrintEndpoint $destino, string $bytes): void
    {
        $ruta = $destino->rutaDeCola();

        // La cola compartida es una cosa de WINDOWS: en otro sistema no
        // existe, y el mensaje lo dice en vez de fallar en silencio.
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException(
                "La cola de la impresora compartida «{$ruta}» solo existe en Windows: este servidor no es Windows."
            );
        }

        $manejador = @fopen($ruta, 'wb');

        if ($manejador === false) {
            throw new RuntimeException(
                "No se pudo abrir la cola de la impresora compartida «{$ruta}»: la impresora no esta disponible."
            );
        }

        try {
            $this->volcar($manejador, $bytes, $ruta);
        } finally {
            fclose($manejador);
        }
    }

    /**
     * Escribe TODOS los bytes: una escritura parcial es un ticket roto.
     *
     * @param  resource  $flujo  El socket o el fichero abierto.
     *
     * @throws RuntimeException Si el destino no acepta todos los bytes.
     */
    private function volcar($flujo, string $bytes, string $destino): void
    {
        $total = strlen($bytes);
        $escritos = 0;

        while ($escritos < $total) {
            $escritosAhora = @fwrite($flujo, substr($bytes, $escritos));

            if ($escritosAhora === false || $escritosAhora === 0) {
                throw new RuntimeException(
                    "La impresora «{$destino}» no acepto todos los bytes del ticket: "
                    ."se escribieron {$escritos} de {$total}."
                );
            }

            $escritos += $escritosAhora;
        }
    }

    /**
     * El trabajo FALLIDO, con su motivo.
     *
     * Si el estado tampoco se puede escribir, el motivo se va al log: perder
     * el motivo es peor que perder el estado, y el trabajo se puede
     * reintentar a mano.
     */
    private function fallar(int $jobId, string $mensaje): void
    {
        try {
            $this->stateMachine->markFailed($jobId, $mensaje);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
