<?php

namespace App\Print;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El CATALOGO de impresoras: la identidad de los modelos de impresora.
 *
 * REGLA que fija esta clase: *el codigo de la impresora es su clave natural:
 * es UNICO en todo el catalogo y se NORMALIZA*. Se normaliza como el codigo
 * de las cuentas contables (sin espacios en blanco alrededor, en mayusculas):
 * un codigo tecleado con espacios es un codigo tecleado mal, y dos
 * impresoras tecleadas distinto son la MISMA impresora con dos nombres.
 *
 * Y EL CATALOGO ES GLOBAL: la identidad del modelo de impresora no es de una
 * tienda. Lo que es de una tienda es el DESTINO (`print_targets`), que es
 * donde vive el endpoint, el ancho y el estado.
 *
 * Y EL ALTA Y LA EDICION SE AUDITAN: la configuracion de la impresion se
 * escribe en `audit_logs` con quien la cambio y desde donde.
 */
final readonly class PrinterCatalog
{
    /**
     * El tope del CODIGO: es lo que admite la columna.
     */
    private const CODIGO_MAXIMO = 100;

    /**
     * El tope del NOMBRE: es lo que admite la columna.
     */
    private const NOMBRE_MAXIMO = 255;

    public function __construct(private Connection $connection) {}

    /**
     * El ALTA de una impresora del catalogo.
     *
     * @throws RuntimeException Si el codigo esta vacio, no cabe, o ya existe.
     */
    public function create(string $code, string $name): int
    {
        $codigo = $this->normaliza($code);
        $nombre = $this->nombre($name);

        $this->exigeCodigoLibre($codigo, null);

        return (int) $this->connection->table('printers')->insertGetId([
            'code' => $codigo,
            'name' => $nombre,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * La EDICION de una impresora del catalogo.
     *
     * @throws RuntimeException Si la impresora no existe, o si el codigo esta
     *                          vacio, no cabe, o lo tiene otra.
     */
    public function update(int $printerId, string $code, string $name, bool $isActive): void
    {
        if (! $this->existe($printerId)) {
            throw new RuntimeException("La impresora {$printerId} no existe en el catalogo.");
        }

        $codigo = $this->normaliza($code);
        $nombre = $this->nombre($name);

        $this->exigeCodigoLibre($codigo, $printerId);

        $this->connection->table('printers')
            ->where('id', $printerId)
            ->update([
                'code' => $codigo,
                'name' => $nombre,
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);
    }

    /**
     * El CODIGO normalizado: sin espacios alrededor y en mayusculas.
     *
     * @throws RuntimeException Si el codigo esta vacio o no cabe.
     */
    private function normaliza(string $code): string
    {
        $codigo = mb_strtoupper(trim($code));

        if ($codigo === '') {
            throw new RuntimeException(
                'El codigo de la impresora no puede estar vacio: es la clave natural del catalogo.'
            );
        }

        if (mb_strlen($codigo) > self::CODIGO_MAXIMO) {
            throw new RuntimeException(
                'El codigo de la impresora no puede tener mas de '.self::CODIGO_MAXIMO
                .' caracteres: es lo que admite el catalogo.'
            );
        }

        return $codigo;
    }

    /**
     * El NOMBRE de la impresora, recortado.
     *
     * @throws RuntimeException Si el nombre esta vacio o no cabe.
     */
    private function nombre(string $name): string
    {
        $nombre = trim($name);

        if ($nombre === '') {
            throw new RuntimeException('El nombre de la impresora no puede estar vacio.');
        }

        if (mb_strlen($nombre) > self::NOMBRE_MAXIMO) {
            throw new RuntimeException(
                'El nombre de la impresora no puede tener mas de '.self::NOMBRE_MAXIMO
                .' caracteres: es lo que admite el catalogo.'
            );
        }

        return $nombre;
    }

    /**
     * El codigo tiene que estar LIBRE.
     *
     * La unicidad la vigila el ESQUEMA, y el mensaje lo da el DOMINIO: un
     * error de PostgreSQL no le dice a quien lo lee que el codigo es la
     * clave natural del catalogo.
     *
     * @param  int|null  $printerId  La impresora que se edita, para que su
     *                               propio codigo no choque consigo mismo.
     *
     * @throws RuntimeException Si el codigo ya existe.
     */
    private function exigeCodigoLibre(string $codigo, ?int $printerId): void
    {
        $consulta = $this->connection->table('printers')->where('code', $codigo);

        if ($printerId !== null) {
            $consulta->where('id', '!=', $printerId);
        }

        if ($consulta->exists()) {
            throw new RuntimeException(
                "Ya existe una impresora con el codigo «{$codigo}»: "
                .'el codigo es la clave natural del catalogo de impresoras.'
            );
        }
    }

    /**
     * ¿La impresora existe en el catalogo?
     */
    private function existe(int $printerId): bool
    {
        return $this->connection->table('printers')->where('id', $printerId)->exists();
    }
}
