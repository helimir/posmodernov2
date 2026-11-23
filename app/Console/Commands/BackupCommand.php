<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupRunner;
use Illuminate\Console\Command;

/**
 * El comando que HACE el backup de la base.
 *
 * REGLA que fija este comando: *un backup que no se ha verificado no es un
 * backup*. Por eso el motor verifica el fichero con `pg_restore --list`
 * ANTES de darlo por bueno, y **borra** el fichero si no se puede leer:
 * un backup roto en la carpeta ocupa el sitio del bueno y-engaña a quien
 * lo vea.
 *
 * Y LA RETENCION va aqui: el backup que se hace es el que limpia los
 * viejos, porque si no, la carpeta crece sin limite y el dia que falta
 * espacio es el dia que no cabe el backup que hacia falta.
 */
class BackupCommand extends Command
{
    /**
     * La firma del comando.
     */
    protected $signature = 'ops:backup
                            {--database= : La base que se respalda; por defecto, la de la aplicacion}
                            {--file= : Donde se deja el fichero; por defecto, la carpeta de backups}';

    /**
     * La descripcion del comando.
     */
    protected $description = 'Hace un backup verificado de la base y purga los backups viejos.';

    /**
     * La EJECUCION del comando.
     */
    public function handle(BackupRunner $runner): int
    {
        $database = (string) ($this->option('database') ?: config('database.connections.pgsql.database'));
        $fichero = $this->option('file');

        $this->line("Respaldando «{$database}» con el driver «{$runner->driver()}»...");

        try {
            $ruta = $runner->backup($database, $fichero === null ? null : (string) $fichero);
        } catch (\Throwable $fallo) {
            $this->error($fallo->getMessage());

            return self::FAILURE;
        }

        $tamano = number_format((float) filesize($ruta) / 1048576, 2);

        $this->info(sprintf('Backup listo: %s (%s MB)', $ruta, $tamano));

        $borrados = $runner->purgar();

        if ($borrados > 0) {
            $this->line(sprintf('Se purgaron %d backup(s) que superaron la retencion.', $borrados));
        }

        return self::SUCCESS;
    }
}
