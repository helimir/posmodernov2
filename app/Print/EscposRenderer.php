<?php

namespace App\Print;

use App\Models\PrintTarget;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\Printer;
use RuntimeException;

/**
 * El generador de los bytes ESC/POS, en el SERVIDOR.
 *
 * El agente LAN no sabe imprimir: recibe bytes ya generados y sólo los
 * escribe (design D11, ADR-0005). Por eso el servidor es el único que puede
 * decir "este ticket no es representable": si el destino no soporta QR, o si
 * el QR no cabe, el fallo ocurre AQUÍ, antes de enviar nada. Un ticket mudo
 * en el papel es un fallo del backoffice, no de la impresora.
 *
 * El PERFIL de impresora es DATO (design D5): el destino declara su
 * codepágina, su perfil ESC/POS y su soporte de QR, y el renderer consulta
 * las capacidades reales de la librería antes de componer nada.
 */
final readonly class EscposRenderer
{
    /**
     * La codepágina por defecto. CP850 y no CP858 porque CP858 NO es
     * codificable en la librería: la tabla 19 se declara, pero sin tabla de
     * conversion, y el texto saldría con signos de interrogación.
     */
    private const DEFAULT_CHARSET = 'CP850';

    /**
     * Los estilos de línea que el renderer sabe aplicar. Cualquier otro
     * estilo es un error: un estilo desconocido no puede imprimir un ticket
     * distinto del que se pidió.
     */
    private const STYLES = ['bold', 'double'];

    public function __construct(private Connection $connection) {}

    /**
     * Genera los bytes ESC/POS del payload para el destino dado.
     *
     * @throws RuntimeException Si el ticket no es representable en el destino.
     */
    public function render(PrintTarget $target, PrintPayload $payload): RenderedPayload
    {
        // Los bytes se generan contra la configuracion CONGELADA en la base,
        // no contra un modelo en memoria que could've sido modificado: el
        // destino es DATO, y el dato que vale es el que esta guardado.
        $configuracion = $this->configuracion($target);

        $perfil = $this->perfil($configuracion['printer_profile']);
        $tabla = $this->tablaDeCodepagina($perfil, $configuracion['charset']);

        $this->verificarQr($configuracion, $perfil, $payload);

        $conector = new BufferPrintConnector;

        try {
            $impresora = new Printer($conector, $perfil);

            // La codepagina del destino, antes de escribir una sola letra.
            $impresora->selectCharacterTable($tabla);

            // El titulo, en negrita.
            if ($payload->title !== '') {
                $impresora->setEmphasis(true);
                $impresora->text($payload->title);
                $impresora->setEmphasis(false);
                $impresora->feed(1);
            }

            // Las lineas del ticket.
            foreach ($payload->lines as $linea) {
                $this->escribirLinea($impresora, $linea);
            }

            // El QR, cuando el destino lo admite.
            if ($payload->qrContent !== null && $payload->qrContent !== '') {
                $impresora->qrCode($payload->qrContent);
                $impresora->feed(1);
            }
        } finally {
            // Los bytes quedan congelados aunque la composicion falle: el
            // trabajo no se crea a medias.
            $conector->finalize();
        }

        $bytes = $conector->getData();

        return new RenderedPayload($bytes, hash('sha256', $bytes));
    }

    /**
     * La configuracion guardada del destino.
     *
     * @return array{printer_profile: string, charset: string, supports_qr: bool, max_qr_payload_bytes: int}
     */
    private function configuracion(PrintTarget $target): array
    {
        $fila = $this->connection->table('print_targets')->where('id', $target->getKey())->first();

        if ($fila === null) {
            throw new RuntimeException("El destino de impresion {$target->getKey()} no existe.");
        }

        return [
            'printer_profile' => (string) $fila->printer_profile,
            'charset' => (string) $fila->charset,
            // PostgreSQL devuelve el booleano como `bool`; se acepta tambien
            // la forma textual por si el driver cambia.
            'supports_qr' => in_array($fila->supports_qr, [true, 1, '1', 't', 'true'], true),
            'max_qr_payload_bytes' => (int) $fila->max_qr_payload_bytes,
        ];
    }

    /**
     * El perfil ESC/POS de la libreria.
     */
    private function perfil(string $nombre): CapabilityProfile
    {
        try {
            return CapabilityProfile::load($nombre);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException(
                "El perfil de impresora «{$nombre}» no existe en la libreria de impresion: ".$error->getMessage(),
                previous: $error
            );
        }
    }

    /**
     * El numero de tabla de la codepagina pedida, en el perfil dado.
     *
     * La codepagina es un DATO del destino y la tabla es un dato del perfil:
     * la libreria relaciona ambas (`getCodePages()`), asi que no hay una
     * tabla fija en el codigo.
     */
    private function tablaDeCodepagina(CapabilityProfile $perfil, string $codepagina): int
    {
        $codepagina = strtoupper(trim($codepagina));

        if ($codepagina === '') {
            $codepagina = self::DEFAULT_CHARSET;
        }

        foreach ($perfil->getCodePages() as $tabla => $codePage) {
            if (strtoupper($codePage->getId()) !== $codepagina) {
                continue;
            }

            if (! $codePage->isEncodable()) {
                throw new RuntimeException(
                    "La codepagina {$codepagina} no es codificable en la libreria de impresion: "
                    .'usa CP850 en su lugar.'
                );
            }

            return (int) $tabla;
        }

        throw new RuntimeException(
            "El perfil de impresora «{$perfil->getId()}» no declara la codepagina {$codepagina}."
        );
    }

    /**
     * El QR se verifica ANTES de componer una sola letra.
     *
     * @param  array{printer_profile: string, charset: string, supports_qr: bool, max_qr_payload_bytes: int}  $configuracion
     */
    private function verificarQr(array $configuracion, CapabilityProfile $perfil, PrintPayload $payload): void
    {
        if ($payload->qrContent === null || $payload->qrContent === '') {
            return;
        }

        if (! $configuracion['supports_qr']) {
            throw new RuntimeException(
                'El destino de impresion no soporta codigos QR: el ticket no es representable y no se envia.'
            );
        }

        if (! $perfil->getSupportsQrCode()) {
            throw new RuntimeException(
                "El perfil de impresora «{$configuracion['printer_profile']}» no soporta codigos QR: "
                .'el ticket no es representable y no se envia.'
            );
        }

        // El limite del payload del QR lo impone el SERVIDOR: ninguna
        // libreria lo valida, y el buffer real del modelo no es verificable
        // desde aqui (design D5).
        $limite = $configuracion['max_qr_payload_bytes'];
        $tamano = strlen($payload->qrContent);

        if ($tamano > $limite) {
            throw new RuntimeException(
                "El contenido del QR ({$tamano} bytes) supera el limite del destino ({$limite} bytes)."
            );
        }
    }

    /**
     * Una linea del ticket, con su estilo.
     *
     * @param  array{text: string, style?: string}  $linea
     */
    private function escribirLinea(Printer $impresora, array $linea): void
    {
        $texto = (string) ($linea['text'] ?? '');
        $estilo = isset($linea['style']) ? (string) $linea['style'] : null;

        // El estilo se VALIDA antes de escribir: un estilo desconocido no
        // puede dejar la impresora a medias.
        if ($estilo !== null && ! in_array($estilo, self::STYLES, true)) {
            throw new RuntimeException(
                "El estilo «{$estilo}» no existe en el renderer ESC/POS. "
                .'Los estilos admitidos son: '.implode(', ', self::STYLES).'.'
            );
        }

        if ($estilo === 'bold') {
            $impresora->setEmphasis(true);
        } elseif ($estilo === 'double') {
            $impresora->setTextSize(2, 2);
        }

        $impresora->text($texto);
        $impresora->feed(1);

        if ($estilo === 'bold') {
            $impresora->setEmphasis(false);
        } elseif ($estilo === 'double') {
            $impresora->setTextSize(1, 1);
        }
    }
}
