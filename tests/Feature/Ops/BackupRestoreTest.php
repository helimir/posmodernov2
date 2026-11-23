<?php

use App\Support\Backup\BackupRunner;
use Illuminate\Support\Facades\DB;

/**
 * El BACKUP y su RESTAURACION.
 *
 * REGLA que fija esta unidad: *un backup que no se ha restaurado no es un
 * backup*. Por eso este test no comprueba que el fichero existe: comprueba
 * que, **despues** de restaurar, los datos **vuelven**.
 *
 * Y LA BASE DE LA PRUEBA ES PROPIA: el test trabaja en una base aparte
 * (`pos_moderno_v2_restore_test`) porque restaurar **pisa**, y pisar la
 * base de la suite dejaria a los demas tests sin base. Por eso la base se
 * crea y se tira al final.
 */
describe('el backup y su restauracion', function () {
    /**
     * La base de la prueba. Es una base APARTE: restaurar pisa.
     */
    function baseDePrueba(): string
    {
        return 'pos_moderno_v2_restore_test';
    }

    /**
     * Crea la base de la prueba, vacia, y devuelve la conexion a ella.
     *
     * LA CONEXION ES APARTE (`pgsql_b`) y sin transaccion: `CREATE DATABASE`
     * no puede ejecutarse dentro de una transaccion, y la de la suite lo
     * esta.
     */
    function crearBaseDePrueba(): void
    {
        // La base de MANTENIMIENTO: la que existe siempre y desde la que
        // se crean las demas.
        config(['database.connections.pgsql_b.database' => 'postgres']);
        DB::purge('pgsql_b');

        $mantenimiento = DB::connection('pgsql_b');
        $nombre = baseDePrueba();

        $existe = $mantenimiento->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$nombre]
        );

        if ($existe !== null) {
            $mantenimiento->statement('DROP DATABASE IF EXISTS '.$nombre);
        }

        $mantenimiento->statement('CREATE DATABASE '.$nombre);

        // Y ahora la conexion a la base NUEVA.
        config(['database.connections.pgsql_b.database' => $nombre]);
        DB::purge('pgsql_b');

        DB::connection('pgsql_b')->statement(
            'CREATE TABLE IF NOT EXISTS prueba_backup (id serial primary key, texto text not null)'
        );
    }

    /**
     * Tira la base de la prueba.
     */
    function tirarBaseDePrueba(): void
    {
        config(['database.connections.pgsql_b.database' => 'postgres']);
        DB::purge('pgsql_b');

        $mantenimiento = DB::connection('pgsql_b');

        try {
            $mantenimiento->statement('DROP DATABASE IF EXISTS '.baseDePrueba());
        } catch (Throwable) {
            // Si no se puede tirar, no se rompe la suite: la base es de
            // pruebas y el proximo test la vuelve a crear.
        }
    }

    beforeEach(function () {
        config(['backup.path' => storage_path('testing/backups')]);

        if (is_dir(storage_path('testing/backups'))) {
            foreach (glob(storage_path('testing/backups').DIRECTORY_SEPARATOR.'*') ?: [] as $fichero) {
                @unlink($fichero);
            }
        }

        crearBaseDePrueba();
    });

    afterEach(function () {
        tirarBaseDePrueba();
    });

    it('hace un backup verificado de la base', function () {
        $runner = app(BackupRunner::class);

        $fichero = $runner->backup(baseDePrueba());

        expect(is_file($fichero))->toBeTrue()
            ->and(filesize($fichero))->toBeGreaterThan(0)
            ->and($runner->verificar($fichero))->toBeTrue();
    });

    it('RESTAURA los datos: despues de restaurar, los datos vuelven', function () {
        $otra = DB::connection('pgsql_b');

        $otra->table('prueba_backup')->insert(['texto' => 'el original']);

        $runner = app(BackupRunner::class);
        $fichero = $runner->backup(baseDePrueba());

        // Y AHORA SE DESTRUYE: la fila se borra y se pone otra.
        $otra->table('prueba_backup')->truncate();
        $otra->table('prueba_backup')->insert(['texto' => 'lo que cambio']);

        expect($otra->table('prueba_backup')->value('texto'))->toBe('lo que cambio');

        $runner->restore(baseDePrueba(), $fichero);

        // La conexion tiene que VER la restauracion: la base se restauro
        // por fuera, asi que la conexion se reconecta.
        DB::purge('pgsql_b');
        $otra = DB::connection('pgsql_b');

        expect($otra->table('prueba_backup')->value('texto'))->toBe('el original');
    });

    it('el comando de restore sin force no toca la base', function () {
        $otra = DB::connection('pgsql_b');
        $otra->table('prueba_backup')->insert(['texto' => 'intacto']);

        $runner = app(BackupRunner::class);
        $fichero = $runner->backup(baseDePrueba());

        $this->artisan('ops:restore', [
            '--file' => $fichero,
            '--database' => baseDePrueba(),
        ])
            ->expectsOutputToContain('el comando no hace nada')
            ->assertExitCode(1);

        DB::purge('pgsql_b');

        expect(DB::connection('pgsql_b')->table('prueba_backup')->value('texto'))->toBe('intacto');
    });

    it('el comando de restore con force restaura', function () {
        $otra = DB::connection('pgsql_b');
        $otra->table('prueba_backup')->insert(['texto' => 'el original']);

        $runner = app(BackupRunner::class);
        $fichero = $runner->backup(baseDePrueba());

        $otra->table('prueba_backup')->truncate();
        $otra->table('prueba_backup')->insert(['texto' => 'lo que cambio']);

        $this->artisan('ops:restore', [
            '--file' => $fichero,
            '--database' => baseDePrueba(),
            '--force' => true,
        ])->assertExitCode(0);

        DB::purge('pgsql_b');

        expect(DB::connection('pgsql_b')->table('prueba_backup')->value('texto'))->toBe('el original');
    });

    it('un backup ilegible no se restaura', function () {
        $fichero = storage_path('testing/backups/roto.dump');
        file_put_contents($fichero, 'esto no es un dump');

        $runner = app(BackupRunner::class);

        expect($runner->verificar($fichero))->toBeFalse();

        $this->artisan('ops:restore', [
            '--file' => $fichero,
            '--database' => baseDePrueba(),
            '--force' => true,
        ])
            ->expectsOutputToContain('no se puede leer')
            ->assertExitCode(1);
    });

    it('la retencion purga los backups viejos y respeta el suelo', function () {
        $runner = app(BackupRunner::class);

        $carpeta = $runner->carpeta();

        $viejo = $carpeta.DIRECTORY_SEPARATOR.'viejo.dump';
        $nuevo = $carpeta.DIRECTORY_SEPARATOR.'nuevo.dump';

        file_put_contents($viejo, 'x');
        file_put_contents($nuevo, 'x');

        touch($viejo, now()->subDays(60)->getTimestamp());
        touch($nuevo, now()->subDay()->getTimestamp());

        config(['backup.retention_days' => 30, 'backup.retention_floor_days' => 7]);

        expect($runner->purgar())->toBe(1)
            ->and(is_file($viejo))->toBeFalse()
            ->and(is_file($nuevo))->toBeTrue();

        // Y CON EL SUELO: una retencion de un dia no borra nada, porque
        // por debajo del suelo no se toca.
        touch($viejo, now()->subDays(60)->getTimestamp());
        config(['backup.retention_days' => 1, 'backup.retention_floor_days' => 7]);

        expect($runner->purgar())->toBe(0)
            ->and(is_file($viejo))->toBeTrue();
    });
});
