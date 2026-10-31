<?php

use Illuminate\Support\Facades\DB;

/**
 * 0.1.2-T — el bloqueo de fila se prueba con dos sesiones PostgreSQL distintas sobre la misma base.
 */
test('test_la_segunda_conexion_pgsql_b_apunta_a_la_misma_base_de_pruebas', function () {
    expect(config('database.connections.pgsql_b.database'))
        ->toBe(config('database.connections.pgsql.database'));

    expect(DB::connection('pgsql_b')->getDatabaseName())
        ->toBe(DB::connection('pgsql')->getDatabaseName());
});
