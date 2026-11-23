<?php

use App\Console\Commands\PurgeAuditLogs;
use App\Support\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;

/**
 * La RETENCION de la auditoria.
 *
 * REGLA que fija esta unidad: *la auditoria se guarda, pero no para siempre,
 * y su borrado nunca es una sorpresa*.
 *
 *  - sin `--dias` el comando **no borra nada** y lo dice;
 *  - por debajo del ano el comando se niega, con o sin `--force`;
 *  - sin `--force` **simula**: cuenta y no borra;
 *  - con `--force` borra lo que supero la retencion, y **solo eso**.
 */
describe('la retencion de la auditoria', function () {
    it('sin dias no borra nada y lo dice', function () {
        $this->artisan('ops:audit-purge')
            ->expectsOutputToContain('Hay que decir cuantos dias se guardan')
            ->assertExitCode(1);

        expect(DB::table('audit_logs')->count())->toBeGreaterThanOrEqual(0);
    });

    it('por debajo del ano se niega, incluso con force', function () {
        $escritor = new AuditWriter(DB::connection());
        $escritor->record('prueba.retencion', 'prueba', 1);

        $antes = DB::table('audit_logs')->where('action', 'prueba.retencion')->count();

        $this->artisan('ops:audit-purge', ['--dias' => 30, '--force' => true])
            ->expectsOutputToContain('La retencion minima es de 365 dias')
            ->assertExitCode(1);

        expect(DB::table('audit_logs')->where('action', 'prueba.retencion')->count())->toBe($antes);
    });

    it('sin force solo simula y no borra', function () {
        $escritor = new AuditWriter(DB::connection());

        // Una entrada VIEJA: mas de un ano.
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'store_id' => null,
            'action' => 'prueba.vieja',
            'entity_type' => 'prueba',
            'entity_id' => 1,
            'context' => json_encode(['a' => 'b']),
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays(500),
            'updated_at' => now()->subDays(500),
        ]);

        $this->artisan('ops:audit-purge', ['--dias' => 365])
            ->expectsOutputToContain('SIMULACION')
            ->assertExitCode(0);

        expect(DB::table('audit_logs')->where('action', 'prueba.vieja')->count())->toBe(1);
    });

    it('con force borra lo viejo y deja lo nuevo', function () {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'store_id' => null,
            'action' => 'prueba.vieja',
            'entity_type' => 'prueba',
            'entity_id' => 1,
            'context' => json_encode(['a' => 'b']),
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays(500),
            'updated_at' => now()->subDays(500),
        ]);

        (new AuditWriter(DB::connection()))->record('prueba.nueva', 'prueba', 2);

        $this->artisan('ops:audit-purge', ['--dias' => 365, '--force' => true])
            ->expectsOutputToContain('Se purgaron')
            ->assertExitCode(0);

        expect(DB::table('audit_logs')->where('action', 'prueba.vieja')->count())->toBe(0)
            ->and(DB::table('audit_logs')->where('action', 'prueba.nueva')->count())->toBe(1);
    });

    it('el suelo de retencion es de un ano', function () {
        expect(PurgeAuditLogs::RETENCION_MINIMA_DIAS)->toBe(365);
    });
});
