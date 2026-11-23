<?php

namespace App\Support\Backup;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El MOTOR de los backups y las restauraciones.
 *
 * REGLA que fija esta clase: *un backup que no se ha restaurado no es un
 * backup*. Por eso aqui no se escribe un fichero y ya: se **verifica** que
 * `pg_restore` lo puede leer, y se **restaura de verdad** cuando toca.
 *
 * Y EL MISMO CODIGO HABLA LOS DOS IDIOMAS:
 *
 *  - `pg_dump`: las herramientas de PostgreSQL estan en el PATH del host
 *    (el servidor de produccion, la CI);
 *  - `docker`: estan DENTRO del contenedor de la base (un desarrollo con la
 *    base en Docker, donde el host no tiene ni `pg_dump`).
 *
 * Un backup que solo funciona en una maquina es un backup que no existe el
 * dia de la recuperacion, que es el unico dia en que importa.
 */
final class BackupRunner
{
    /**
     * El driver RESUELTO: `pg_dump` o `docker`.
     *
     * Se resuelve una vez y se recuerda: preguntar al PATH en cada backup
     * es trabajo tirado, y un backup que a veces usa un driver y otras otro
     * es un backup que nadie sabe que hay.
     */
    private ?string $driverResuelto = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * El DRIVER que se va a usar.
     *
     * `auto` decide: `pg_dump` si esta en el PATH, `docker` si no.
     */
    public function driver(): string
    {
        if ($this->driverResuelto !== null) {
            return $this->driverResuelto;
        }

        $configurado = (string) config('backup.driver', 'auto');

        if ($configurado === 'pg_dump' || $configurado === 'docker') {
            return $this->driverResuelto = $configurado;
        }

        return $this->driverResuelto = $this->estaEnElPath((string) config('backup.pg_dump', 'pg_dump'))
            ? 'pg_dump'
            : 'docker';
    }

    /**
     * La CARPETA de los backups, creada si no existe.
     */
    public function carpeta(): string
    {
        $carpeta = (string) config('backup.path', storage_path('backups'));

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        return $carpeta;
    }

    /**
     * El NOMBRE de un backup: base, fecha y hora.
     */
    public function nombre(string $database): string
    {
        return sprintf('%s-%s.dump', $database, now()->format('Ymd-His'));
    }

    /**
     * Hace el BACKUP de una base y devuelve la ruta del fichero.
     *
     * Y VERIFICA el resultado: un fichero que `pg_restore` no puede leer no
     * es un backup, es un fichero. Si la verificacion falla, el fichero
     * objectionable se **borra**: dejar un backup roto en la carpeta es
     * peor que no dejar nada, porque ocupa el sitio del bueno.
     *
     * @throws RuntimeException Si el backup falla o no se puede verificar.
     */
    public function backup(string $database, ?string $fichero = null): string
    {
        $fichero = $fichero ?? $this->carpeta().DIRECTORY_SEPARATOR.$this->nombre($database);

        [$codigo, $salida] = $this->descargar($database, $fichero);

        if ($codigo !== 0) {
            @unlink($fichero);

            throw new RuntimeException(
                "El backup de «{$database}» fallo (codigo {$codigo}): ".trim($salida)
            );
        }

        if (! is_file($fichero) || filesize($fichero) === 0) {
            @unlink($fichero);

            throw new RuntimeException("El backup de «{$database}» salio vacio.");
        }

        if (! $this->verificar($fichero)) {
            @unlink($fichero);

            throw new RuntimeException(
                "El backup de «{$database}» se escribio pero no se puede leer: no es un backup."
            );
        }

        return $fichero;
    }

    /**
     * VERIFICA que un fichero es un dump que `pg_restore` puede leer.
     *
     * Es la LISTA del dump: si `pg_restore --list` la puede leer, el
     * fichero esta completo y es restaurable.
     */
    public function verificar(string $fichero): bool
    {
        if (! is_file($fichero)) {
            return false;
        }

        if ($this->driver() === 'docker') {
            $remoto = $this->copiarAlContenedor($fichero);

            if ($remoto === null) {
                return false;
            }

            [$codigo] = $this->ejecutar(sprintf(
                'docker exec %s pg_restore --list %s',
                $this->contenedor(),
                $this->comando($remoto),
            ));

            $this->borrarDelContenedor($remoto);

            return $codigo === 0;
        }

        [$codigo] = $this->ejecutar(sprintf(
            '%s --list %s',
            $this->comando((string) config('backup.pg_restore', 'pg_restore')),
            $this->comando($fichero),
        ));

        return $codigo === 0;
    }

    /**
     * RESTAURA un backup sobre una base.
     *
     * Y ESTO PISA: por eso el comando que lo llama pide `--force` de forma
     * explicita. Aqui no hay confirmacion, porque la confirmacion es del
     * comando: la que sabe lo que esta a punto de perder es la persona que
     * lo ejecuta.
     *
     * @throws RuntimeException Si la restauracion falla.
     */
    public function restore(string $database, string $fichero): void
    {
        if (! is_file($fichero)) {
            throw new RuntimeException("El backup «{$fichero}» no existe.");
        }

        if (! $this->verificar($fichero)) {
            throw new RuntimeException(
                "El backup «{$fichero}» no se puede leer: restaurarlo seria dejar la base vacia."
            );
        }

        if ($this->driver() === 'docker') {
            $remoto = $this->copiarAlContenedor($fichero);

            if ($remoto === null) {
                throw new RuntimeException('No se pudo copiar el backup al contenedor.');
            }

            [$codigo, $salida] = $this->ejecutar(sprintf(
                'docker exec %s pg_restore -U %s -d %s --clean --if-exists %s',
                $this->contenedor(),
                $this->usuario(),
                $this->comando($database),
                $this->comando($remoto),
            ));

            $this->borrarDelContenedor($remoto);
        } else {
            $this->conPassword(function (): void {
                [$codigo, $salida] = $this->ejecutar(sprintf(
                    '%s -h %s -p %s -U %s -d %s --clean --if-exists %s',
                    $this->comando((string) config('backup.pg_restore', 'pg_restore')),
                    $this->comando((string) config('database.connections.pgsql.host', '127.0.0.1')),
                    $this->comando((string) config('database.connections.pgsql.port', '5432')),
                    $this->comando($this->usuario()),
                    $this->comando($database),
                    $this->comando($fichero),
                ));
            });
        }

        if ($codigo !== 0) {
            throw new RuntimeException(
                "La restauracion de «{$database}» fallo (codigo {$codigo}): ".trim((string) $salida)
            );
        }
    }

    /**
     * PURGA los backups que superaron la retencion.
     *
     * Y CON UN SUELO: por debajo de `retention_floor_days` no se borra
     * nada. Un backup es la ultima linea de defensa; si el reloj va mal, el
     * primer backup que se lleva por delante es el unico que hay.
     *
     * @return int Cuantos ficheros se borraron.
     */
    public function purgar(): int
    {
        $dias = (int) config('backup.retention_days', 30);
        $suelo = (int) config('backup.retention_floor_days', 7);

        if ($dias < $suelo) {
            return 0;
        }

        $corte = now()->subDays($dias)->getTimestamp();
        $borrados = 0;

        foreach ($this->ficheros() as $fichero) {
            if (filemtime($fichero) < $corte) {
                if (@unlink($fichero)) {
                    $borrados++;
                }
            }
        }

        return $borrados;
    }

    /**
     * Los FICHEROS de backup de la carpeta.
     *
     * @return list<string>
     */
    public function ficheros(): array
    {
        $carpeta = $this->carpeta();

        if (! is_dir($carpeta)) {
            return [];
        }

        $ficheros = glob($carpeta.DIRECTORY_SEPARATOR.'*.dump');

        if ($ficheros === false) {
            return [];
        }

        sort($ficheros);

        return array_values($ficheros);
    }

    /**
     * El backup MAS RECIENTE de la carpeta.
     */
    public function ultimo(): ?string
    {
        $ficheros = $this->ficheros();

        if ($ficheros === []) {
            return null;
        }

        return $ficheros[count($ficheros) - 1];
    }

    /**
     * Descarga el dump de la base al fichero.
     *
     * @return array{0: int, 1: string}
     */
    private function descargar(string $database, string $fichero): array
    {
        if ($this->driver() === 'docker') {
            // El `pg_dump` del contenedor escribe en STDOUT y el shell lo
            // deja en el fichero: los bytes van tal cual, que es lo que un
            // dump en formato custom necesita.
            return $this->ejecutar(sprintf(
                'docker exec %s pg_dump -U %s -d %s -Fc > %s',
                $this->contenedor(),
                $this->usuario(),
                $this->comando($database),
                $this->comando($fichero),
            ));
        }

        return $this->conPassword(function () use ($database, $fichero): array {
            return $this->ejecutar(sprintf(
                '%s -h %s -p %s -U %s -d %s -Fc -f %s',
                $this->comando((string) config('backup.pg_dump', 'pg_dump')),
                $this->comando((string) config('database.connections.pgsql.host', '127.0.0.1')),
                $this->comando((string) config('database.connections.pgsql.port', '5432')),
                $this->comando($this->usuario()),
                $this->comando($database),
                $this->comando($fichero),
            ));
        });
    }

    /**
     * Copia un fichero al contenedor y devuelve su ruta dentro.
     */
    private function copiarAlContenedor(string $fichero): ?string
    {
        $remoto = '/tmp/backup-'.bin2hex(random_bytes(8)).'.dump';

        [$codigo] = $this->ejecutar(sprintf(
            'docker cp %s %s:%s',
            $this->comando($fichero),
            $this->contenedor(),
            $remoto,
        ));

        return $codigo === 0 ? $remoto : null;
    }

    /**
     * Borra un fichero del contenedor.
     */
    private function borrarDelContenedor(string $remoto): void
    {
        $this->ejecutar(sprintf('docker exec %s rm -f %s', $this->contenedor(), $remoto));
    }

    /**
     * El USUARIO de PostgreSQL.
     */
    private function usuario(): string
    {
        return (string) config('database.connections.pgsql.username', 'postgres');
    }

    /**
     * El CONTENEDOR de Docker.
     */
    private function contenedor(): string
    {
        return (string) config('backup.docker_container', 'pos-moderno-v2-postgres-1');
    }

    /**
     * ¿Esta el binario en el PATH?
     */
    private function estaEnElPath(string $binario): bool
    {
        [$codigo] = $this->ejecutar(sprintf('%s --version', $this->comando($binario)));

        return $codigo === 0;
    }

    /**
     * Ejecuta un comando con la CONTRASEÑA en el entorno.
     *
     * `PGPASSWORD` es la via que PostgreSQL entiende: ponerla en el
     * entorno es la unica que no deja la clave en la linea de ordenes, donde
     * cualquier otro proceso podria leerla.
     *
     * @param  callable(): mixed  $queHacer
     */
    private function conPassword(callable $queHacer): mixed
    {
        $password = (string) config('database.connections.pgsql.password', '');

        putenv('PGPASSWORD='.$password);

        try {
            return $queHacer();
        } finally {
            putenv('PGPASSWORD');
        }
    }

    /**
     * EJECUTA un comando y devuelve su codigo y su salida.
     *
     * @return array{0: int, 1: string}
     */
    private function ejecutar(string $comando): array
    {
        $salida = [];
        $codigo = 0;

        exec($comando.' 2>&1', $salida, $codigo);

        return [$codigo, implode("\n", $salida)];
    }

    /**
     * ENTRECOMILLA un valor para la linea de ordenes.
     */
    private function comando(string $valor): string
    {
        return '"'.str_replace('"', '\"', $valor).'"';
    }
}
