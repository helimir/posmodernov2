<?php

namespace App\Print;

use Mike42\Escpos\PrintConnectors\PrintConnector;
use RuntimeException;

/**
 * Un conector que CAPTURA en memoria en vez de imprimir.
 *
 * La librería sabe escribir a un fichero, a un socket o a la cola del spooler,
 * pero el servidor NO imprime: genera BYTES y los congela en el trabajo
 * (design D11). Sin un conector de memoria, el servidor tendría que imprimir
 * para poder enviar.
 *
 * El buffer sobrevive a `finalize()`: el contenido queda disponible después
 * de cerrar el flujo, para que el renderer pueda leer los bytes después de
 * cerrar la impresora.
 */
final class BufferPrintConnector implements PrintConnector
{
    /** @var resource|null El flujo en memoria; es null cuando ya se finalizo. */
    private $stream;

    /** ¿Se finalizo el conector? */
    private bool $finalized = false;

    /** Los bytes capturados, disponibles despues de finalizar. */
    private string $buffer = '';

    public function __construct()
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el buffer de impresion en memoria.');
        }

        $this->stream = $stream;
    }

    public function write(string $data): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('El buffer de impresion ya esta cerrado.');
        }

        fwrite($this->stream, $data);
    }

    /**
     * Este conector no habla con una impresora: no hay nada que leer.
     */
    public function read(int $len): bool|string
    {
        return false;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        if ($this->stream !== null) {
            rewind($this->stream);
            $this->buffer = (string) stream_get_contents($this->stream);
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * Los bytes capturados.
     */
    public function getData(): string
    {
        if ($this->finalized) {
            return $this->buffer;
        }

        if ($this->stream === null) {
            return '';
        }

        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    public function __destruct()
    {
        if (! $this->finalized) {
            trigger_error('El conector de impresion no se finalizo: se ha cerrado el buffer.', E_USER_NOTICE);
        }
    }
}
