<?php

namespace App\Print;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El REGISTRO de destinos de impresion: el alta y la edicion de los
 * destinos de una tienda.
 *
 * REGLA que fija esta clase: *una tienda DECLARA sus destinos, y el sistema
 * no adivina nada de ellos*. El destino dice que impresora usa, como se
 * escriben los bytes, donde, con que ancho, con que codepagina y si sabe con
 * QR. Y el REGISTRO es quien lo comprueba: si algo de eso no vale, el
 * destino no se escribe y el error dice POR QUE.
 *
 * Y LA VALIDACION DEL ENDPOINT ES DEL DOMINIO: el CHECK del esquema
 * (`print_targets_endpoint_admitted`) es la red de seguridad, no el mensaje.
 * Un endpoint que no se admite falla aqui, con un error que dice QUE
 * endpoint es, QUE transportes admiten endpoint y QUE formas se aceptan.
 *
 * Y EL ANCHO SE DECLARA: un destino sin ancho declarado no puede componer un
 * ticket, asi que el REGISTRO no lo deja crear ni editar: el ancho es parte
 * de la declaracion del destino, no un detalle que se pueda omitir.
 */
final readonly class PrintTargetRegistry
{
    /**
     * El tope del NOMBRE: es lo que admite la columna.
     */
    private const NOMBRE_MAXIMO = 255;

    /**
     * El tope del PERFIL: es lo que admite la columna.
     */
    private const PERFIL_MAXIMO = 100;

    /**
     * El tope de la CODEPAGINA: es lo que admite la columna.
     */
    private const CODEPAGINA_MAXIMO = 20;

    public function __construct(private Connection $connection) {}

    /**
     * El ALTA de un destino de impresion en una tienda.
     *
     * @throws RuntimeException Si algo de lo declarado no vale.
     */
    public function create(int $storeId, PrintTargetData $datos): int
    {
        $fila = $this->prepara($storeId, $datos, null);

        return (int) $this->connection->table('print_targets')->insertGetId(array_merge($fila, [
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * La EDICION de un destino de impresion.
     *
     * @throws RuntimeException Si el destino no existe, o si algo de lo
     *                          declarado no vale.
     */
    public function update(int $targetId, PrintTargetData $datos): void
    {
        $destino = $this->connection->table('print_targets')->where('id', $targetId)->first();

        if ($destino === null) {
            throw new RuntimeException("El destino de impresion {$targetId} no existe.");
        }

        $fila = $this->prepara((int) $destino->store_id, $datos, $targetId);

        $this->connection->table('print_targets')
            ->where('id', $targetId)
            ->update(array_merge($fila, ['updated_at' => now()]));
    }

    /**
     * Lo que se ESCRIBE, despues de comprobarlo todo.
     *
     * @param  int|null  $targetId  El destino que se edita, para que su
     *                              propio nombre no choque consigo mismo.
     * @return array<string, mixed>
     *
     * @throws RuntimeException Si algo de lo declarado no vale.
     */
    private function prepara(int $storeId, PrintTargetData $datos, ?int $targetId): array
    {
        $this->exigeImpresora($datos->printerId);

        $nombre = $this->nombre($datos->name, $storeId, $targetId);

        // El TRANSPORTE decide si el destino tiene endpoint: el navegador es
        // el destino y no escribe bytes, y los fisicos los escriben siempre.
        $transporte = PrintTransport::desde($datos->transport);

        $endpoint = PrintEndpoint::paraTransporte($transporte, $datos->endpoint);

        // El PERFIL y la CODEPAGINA: el perfil tiene que existir en la
        // libreria, y la codepagina tiene que ser una que el perfil declare
        // y que la libreria sepa codificar.
        $perfil = PrintProfiles::carga($this->perfil($datos->printerProfile));

        PrintProfiles::tablaDeCodepagina($perfil, $datos->charset);

        $charset = mb_strtoupper(trim($datos->charset));

        if ($charset === '') {
            $charset = PrintProfiles::PREDETERMINADA;
        }

        return [
            'printer_id' => $datos->printerId,
            'name' => $nombre,
            'transport' => $transporte->value,
            'endpoint' => $endpoint,
            'printer_profile' => $datos->printerProfile,
            'char_per_line' => $this->ancho($datos->charPerLine),
            'charset' => $charset,
            'max_qr_payload_bytes' => $this->limiteDelQr($datos->maxQrPayloadBytes),
            'supports_qr' => $datos->supportsQr,
            'is_active' => $datos->isActive,
        ];
    }

    /**
     * La IMPRESORA del catalogo tiene que existir.
     *
     * @throws RuntimeException Si la impresora no existe.
     */
    private function exigeImpresora(int $printerId): void
    {
        $existe = $this->connection->table('printers')->where('id', $printerId)->exists();

        if (! $existe) {
            throw new RuntimeException(
                "La impresora {$printerId} no existe en el catalogo: "
                .'un destino declara la impresora que usa.'
            );
        }
    }

    /**
     * El NOMBRE del destino, unico dentro de la tienda.
     *
     * @throws RuntimeException Si el nombre esta vacio, no cabe, o ya existe
     *                          en la tienda.
     */
    private function nombre(string $name, int $storeId, ?int $targetId): string
    {
        $nombre = trim($name);

        if ($nombre === '') {
            throw new RuntimeException('El nombre del destino de impresion no puede estar vacio.');
        }

        if (mb_strlen($nombre) > self::NOMBRE_MAXIMO) {
            throw new RuntimeException(
                'El nombre del destino de impresion no puede tener mas de '.self::NOMBRE_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        $consulta = $this->connection->table('print_targets')
            ->where('store_id', $storeId)
            ->where('name', $nombre);

        if ($targetId !== null) {
            $consulta->where('id', '!=', $targetId);
        }

        if ($consulta->exists()) {
            throw new RuntimeException(
                "Ya existe un destino de impresion con el nombre «{$nombre}» en la tienda {$storeId}: "
                .'el nombre del destino es unico por tienda.'
            );
        }

        return $nombre;
    }

    /**
     * El PERFIL de la impresora.
     *
     * @throws RuntimeException Si el perfil esta vacio o no cabe.
     */
    private function perfil(string $perfil): string
    {
        $perfil = trim($perfil);

        if ($perfil === '') {
            throw new RuntimeException(
                'El destino de impresion tiene que declarar el perfil ESC/POS de su impresora.'
            );
        }

        if (mb_strlen($perfil) > self::PERFIL_MAXIMO) {
            throw new RuntimeException(
                'El perfil de impresora no puede tener mas de '.self::PERFIL_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $perfil;
    }

    /**
     * El ANCHO de linea del destino.
     *
     * @throws RuntimeException Si el destino no declara un ancho usable.
     */
    private function ancho(?int $charPerLine): int
    {
        if ($charPerLine === null) {
            throw new RuntimeException(
                'El destino de impresion tiene que declarar el ancho de linea: '
                .'sin ancho declarado no se puede componer un ticket.'
            );
        }

        if ($charPerLine < PrintWidth::ANCHO_MINIMO) {
            throw new RuntimeException(
                'El ancho de linea del destino tiene que ser un numero de caracteres mayor que cero: '
                .'sin ancho declarado no se puede componer un ticket.'
            );
        }

        return $charPerLine;
    }

    /**
     * El LIMITE del payload del QR.
     *
     * @throws RuntimeException Si el limite no es un numero de bytes usable.
     */
    private function limiteDelQr(int $maxQrPayloadBytes): int
    {
        if ($maxQrPayloadBytes < 1) {
            throw new RuntimeException(
                'El limite del payload del QR tiene que ser un numero de bytes mayor que cero: '
                .'es el limite que impone el servidor.'
            );
        }

        return $maxQrPayloadBytes;
    }
}
