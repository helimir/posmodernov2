<?php

namespace App\Print;

use App\Models\PrintTarget;
use Illuminate\Database\Connection;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\Printer;
use RuntimeException;

/**
 * El generador de los bytes ESC/POS, en el SERVIDOR.
 *
 * El agente LAN no sabe imprimir: recibe bytes ya generados y sólo los
 * escribe (design D11, ADR-0005). Por eso el servidor es el único que puede
 * decir "este ticket no es representable": si el destino no soporta QR, o si
 * el QR no cabe, o si una linea no cabe en el ancho declarado, el fallo ocurre
 * AQUÍ, antes de enviar nada. Un ticket mudo en el papel es un fallo del
 * backoffice, no de la impresora.
 *
 * El PERFIL de impresora es DATO (design D5): el destino declara su
 * codepágina, su perfil ESC/POS, su soporte de QR y su ANCHO de linea, y el
 * renderer consulta las capacidades reales de la librería antes de componer
 * nada.
 */
final readonly class EscposRenderer
{
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
     * Los bytes INCLUYEN el corte del papel, y lo llevan porque el agente
     * NO interpreta el ticket: lo recibe hecho y sólo lo escribe (design
     * D11). Un ticket sin cortar se queda colgando del rodillo, y el rodillo
     * no se suelta solo.
     *
     * @throws RuntimeException Si el ticket no es representable en el destino.
     */
    public function render(PrintTarget $target, PrintPayload $payload): RenderedPayload
    {
        // Los bytes se generan contra la configuracion CONGELADA en la base,
        // no contra un modelo en memoria que could've sido modificado: el
        // destino es DATO, y el dato que vale es el que esta guardado.
        $configuracion = $this->configuracion($target);

        $perfil = PrintProfiles::carga($configuracion['printer_profile']);
        $tabla = PrintProfiles::tablaDeCodepagina($perfil, $configuracion['charset']);

        $this->verificarQr($configuracion, $perfil, $payload);

        // El ANCHO se comprueba ANTES de escribir una sola letra: una linea
        // que no cabe no se recorta en silencio, y un ticket recortado es un
        // comprobante FALSO.
        $this->verificarAncho($configuracion, $payload);

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

            // El CORTE del papel, al final y en los bytes del servidor.
            //
            // El corte viaja AQUÍ porque el agente NO interpreta el ticket:
            // lo recibe hecho y sólo lo escribe (design D11). Si el corte
            // quedara fuera de los bytes, el ticket se quedaría colgando del
            // rodillo, y el rodillo no se suelta solo.
            //
            // El corte es COMPLETO (`GS V 65`): es el estándar y no depende
            // del modelo de impresora. El parcial (`GS V 66`) depende.
            $impresora->cut(Printer::CUT_FULL);
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
     * @return array{name: string, printer_profile: string, charset: string, supports_qr: bool, max_qr_payload_bytes: int, char_per_line: int|null}
     */
    private function configuracion(PrintTarget $target): array
    {
        $fila = $this->connection->table('print_targets')->where('id', $target->getKey())->first();

        if ($fila === null) {
            throw new RuntimeException("El destino de impresion {$target->getKey()} no existe.");
        }

        return [
            'name' => (string) $fila->name,
            'printer_profile' => (string) $fila->printer_profile,
            'charset' => (string) $fila->charset,
            // PostgreSQL devuelve el booleano como `bool`; se acepta tambien
            // la forma textual por si el driver cambia.
            'supports_qr' => in_array($fila->supports_qr, [true, 1, '1', 't', 'true'], true),
            'max_qr_payload_bytes' => (int) $fila->max_qr_payload_bytes,
            // El ANCHO lo declara el destino: es un DATO de la tienda, y sin
            // el no se puede componer un ticket.
            'char_per_line' => $fila->char_per_line === null ? null : (int) $fila->char_per_line,
        ];
    }

    /**
     * El ANCHO se comprueba ANTES de escribir una sola letra.
     *
     * REGLA que fija este método: *una linea que no cabe en el ancho NO se
     * recorta en silencio: el ticket falla con un mensaje que la nombre*.
     *
     * Y EL DESTINO TIENE QUE DECLARAR EL ANCHO: un destino sin ancho
     * declarado no puede componer un ticket. El ancho es DATO de la tienda
     * (que impresora tiene, y de que ancho), y el sistema no lo adivina: un
     * ancho inventado produce un ticket que sale cortado en silencio.
     *
     * @param  array{name: string, printer_profile: string, charset: string, supports_qr: bool, max_qr_payload_bytes: int, char_per_line: int|null}  $configuracion
     *
     * @throws RuntimeException Si el destino no declara el ancho, o si una
     *                          linea no cabe.
     */
    private function verificarAncho(array $configuracion, PrintPayload $payload): void
    {
        $ancho = $configuracion['char_per_line'];

        if ($ancho === null || $ancho < PrintWidth::ANCHO_MINIMO) {
            throw new RuntimeException(
                "El destino de impresion «{$configuracion['name']}» no declara el ancho de linea: "
                .'no se puede componer el ticket.'
            );
        }

        // El TITULO es una linea mas: va en negrita, y la negrita no cambia
        // el ancho (el doble ancho si).
        if ($payload->title !== '' && PrintWidth::excede($payload->title, $ancho, 'bold')) {
            throw new RuntimeException(
                PrintWidth::mensaje($payload->title, $ancho, 'bold', $configuracion['name'])
            );
        }

        foreach ($payload->lines as $linea) {
            $texto = (string) ($linea['text'] ?? '');
            $estilo = isset($linea['style']) ? (string) $linea['style'] : null;

            if (PrintWidth::excede($texto, $ancho, $estilo)) {
                throw new RuntimeException(
                    PrintWidth::mensaje($texto, $ancho, $estilo, $configuracion['name'])
                );
            }
        }
    }

    /**
     * El QR se verifica ANTES de componer una sola letra.
     *
     * @param  array{name: string, printer_profile: string, charset: string, supports_qr: bool, max_qr_payload_bytes: int, char_per_line: int|null}  $configuracion
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
