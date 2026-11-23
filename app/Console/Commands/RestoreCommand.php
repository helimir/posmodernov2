<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupRunner;
use Illuminate\Console\Command;

/**
 * El comando que RESTAURA un backup.
 *
 * REGLA que fija este comando: *restaurar PISA la base*, y por eso **exige
 * `--force`**. Sin ese flag el comando no hace NADA: ni toca la base, ni
 * avisa de forma ambigua. Un comando que dice "va a borrar todo" sin
 * confirmacion y sin flag es un comando que alguien ejecuta por costumbre.
 *
 * Y EL BACKUP SE VERIFICA ANTES DE RESTAURAR: si el fichero no se puede
 * leer, la restauracion se niega. Restaurar un backup roto deja la base
 * VACIA, que es peor que no restaurar: la base vacia parece un fallo de
 * PostgreSQL y no de un restore.
 */
class RestoreCommand extends Command
{
    /**
     * La firma del comando.
     */
    protected $signature = 'ops:restore
                            {--file= : El backup que se restaura; por defecto, el mas reciente}
                            {--database= : La base donde se restaura; por defecto, la de la aplicacion}
                            {--force : Restaurar de verdad; sin esto el comando no hace nada}';

    /**
     * La descripcion del comando.
     */
    protected $description = 'Restaura un backup verificado sobre la base.';

    /**
     * La EJECUCION del comando.
     */
    public function handle(BackupRunner $runner): int
    {
        if (! $this->option('force')) {
            $this->error('Restaurar PISA la base. Sin --force el comando no hace nada.');

            return self::FAILURE;
        }

        $database = (string) ($this->option('database') ?: config('database.connections.pgsql.database'));

        $fichero = $this->option('file');

        if ($fichero === null || $fichero === '') {
            $fichero = $runner->ultimo();
        } else {
            $fichero = (string) $fichero;
        }

        if ($fichero === null) {
            $this->error('No hay ningun backup que restaurar.');

            return self::FAILURE;
        }

        if (! is_file($fichero)) {
            $this->error("El backup «{$fichero}» no existe.");

            return self::FAILURE;
        }

        $this->line("Restaurando «{$fichero}» sobre «{$database}»...");

        try {
            $runner->restore($database, $fichero);
        } catch (\Throwable $fallo) {
            $this->error($fallo->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Restauracion terminada sobre «%s».', $database));

        return self::SUCCESS;
    }
}
