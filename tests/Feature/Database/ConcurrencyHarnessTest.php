<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConcurrencyHarness;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.5-T — el bloqueo de fila se prueba con dos sesiones PostgreSQL
 * distintas sobre la misma base, y el arnés no deja residuos entre pruebas.
 *
 * Regla que fija: *el bloqueo de fila se prueba con dos sesiones PostgreSQL
 * distintas sobre la misma base, y el arnés no deja residuos entre pruebas*.
 *
 * Por eso el arnés NO envuelve la prueba en una transacción que se revierte
 * (`WithoutWrappingTransaction`): la segunda sesión es independiente y no ve
 * las filas no commiteadas, así que con esa transacción el `SELECT … FOR
 * UPDATE` de la sesión A no bloquearía a la sesión B y el `SQLSTATE 55P03` no
 * se reproduciría. El aislamiento lo da el `TRUNCATE … RESTART IDENTITY
 * CASCADE` de las tablas tocadas, en el `afterEach`.
 *
 * Y el arnés es REUTILIZABLE: no sabe las tablas de ninguna prueba concreta.
 * Cada prueba declara las suyas.
 */
uses(ConcurrencyHarness::class, WithoutWrappingTransaction::class);

afterEach(function () {
    $this->limpiarBase();
});

test('test_el_arnes_de_concurrencia_deja_la_base_limpia_y_la_segunda_conexion_apunta_a_la_misma_base', function () {
    $this->marcarTablas('audit_logs');

    $a = DB::connection('pgsql');
    $b = $this->segundaSesion();

    // 1. La segunda conexion apunta a la MISMA base que la primera.
    expect($b->getDatabaseName())->toBe($a->getDatabaseName())
        ->and($b->getDatabaseName())->toBe(config('database.connections.pgsql_b.database'));

    // 2. Pero es DE VERDAD otra sesion: el backend de PostgreSQL tiene otro
    //    PID y la conexion PDO es otra.
    $pidA = (int) $a->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $pidB = (int) $b->selectOne('SELECT pg_backend_pid() AS pid')->pid;

    expect($pidB)->not->toBe($pidA)
        ->and($a->getPdo())->not->toBe($b->getPdo());

    // 3. Y por eso lo que A escribe, B no lo ve mientras A no confirma.
    $a->beginTransaction();

    $a->table('audit_logs')->insert([
        'action' => 'prueba',
        'entity_type' => 'arnés',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((int) $b->table('audit_logs')->count())->toBe(0);

    $a->commit();

    expect((int) $b->table('audit_logs')->count())->toBe(1);

    // 4. El `SET LOCAL lock_timeout` va DENTRO de la transaccion de B.
    $timeout = $this->enLaSegundaSesion(function ($connection): string {
        return (string) $connection->selectOne('SHOW lock_timeout')->lock_timeout;
    });

    expect($timeout)->toBe('250ms');

    // 5. Y no CONTAMINA la sesion: `SET LOCAL` se descarta al confirmar, y el
    //    limite de la sesion sigue siendo el de siempre.
    expect((string) $b->selectOne('SHOW lock_timeout')->lock_timeout)->not->toBe('250ms');

    // 6. La limpieza vacia la tabla y REINICIA la identidad: la prueba
    //    siguiente no hereda ni filas ni contadores.
    $this->limpiarBase();

    expect((int) DB::table('audit_logs')->count())->toBe(0);

    $id = (int) DB::table('audit_logs')->insertGetId([
        'action' => 'prueba',
        'entity_type' => 'arnés',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBe(1);
});

test('test_el_arnes_de_concurrencia_no_deja_residuos_entre_pruebas', function () {
    $this->marcarTablas('audit_logs');

    // La prueba anterior escribio en `audit_logs` y no llamo a la limpieza a
    // mano: el `afterEach` la vacio, asi que esta empieza con la base limpia.
    expect((int) DB::table('audit_logs')->count())->toBe(0);
});
