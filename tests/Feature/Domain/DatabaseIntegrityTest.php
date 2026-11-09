<?php

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.1-T — TODA llave foranea tiene indice, y los lotes disponibles estan
 * indexados PARCIALMENTE.
 *
 * Regla que fija: *toda relacion tiene FK real e indexada*.
 *
 * Una FK sin indice no es un problema de estilo: es un RECORRIDO SECUENCIAL
 * en cada escritura que la toca, y en la tabla mas caliente del dominio (los
 * lotes, las lineas de venta, los asientos) ese recorrido se paga en cada
 * cobro de la caja. El indice lo pone el ESQUEMA, no la disciplina de quien
 * escribe.
 *
 * Y el indice de los lotes DISPONIBLES es PARCIAL (`WHERE available > 0`):
 * `available` es una columna GENERATED (M14), asi que no se indexa por si
 * sola, y un lote consumido es un indice que no se consulta nunca.
 *
 * Los CHECK del lote son la otra mitad de la regla: `quantity_consumed` no
 * puede ser negativo y `available` no puede ser negativo. Aqui los hace
 * cumplir el ESQUEMA, y el test lo comprueba insertando, no leyendo el
 * `pg_constraint`.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): en
 * PostgreSQL un error dentro de una transaccion la ABORTA entera, y aqui hace
 * falta que una fila rechazada no se lleve por delante el resto del test.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement('TRUNCATE inventory_lots, products, stores RESTART IDENTITY CASCADE');
});

test('test_toda_llave_foranea_tiene_indice_y_los_lotes_disponibles_estan_indexados_parcialmente', function () {
    // 1. TODAS las FK del esquema, con la columna que las referencia.
    $fks = DB::select(<<<'SQL'
        select src.relname as tabla,
               src_col.attname as columna
          from pg_constraint con
          join pg_class src on src.oid = con.conrelid
          join pg_class tgt on tgt.oid = con.confrelid
          join unnest(con.conkey) with ordinality as sk(attnum, ord) on true
          join pg_attribute src_col
            on src_col.attrelid = con.conrelid and src_col.attnum = sk.attnum
         where con.contype = 'f'
           and src.relnamespace = 'public'::regnamespace
           and array_length(con.conkey, 1) = 1
         order by src.relname, src_col.attname
        SQL);

    expect($fks)->not->toBeEmpty('El esquema no tiene ninguna llave foranea: el recorrido no probaria nada.');

    // 2. Los INDICES, con su PRIMERA columna: un indice cubre la FK cuando
    //    su primera columna es la de la FK.
    $indices = DB::select(<<<'SQL'
        select t.relname as tabla,
               a.attname as columna
          from pg_index x
          join pg_class i on i.oid = x.indexrelid
          join pg_class t on t.oid = x.indrelid
          join pg_namespace n on n.oid = t.relnamespace
          join lateral unnest(x.indkey) with ordinality as k(attnum, ord) on true
          join pg_attribute a on a.attrelid = x.indrelid and a.attnum = k.attnum
         where n.nspname = 'public'
           and k.ord = 1
        SQL);

    $cubiertas = [];

    foreach ($indices as $indice) {
        $cubiertas[$indice->tabla.'.'.$indice->columna] = true;
    }

    foreach ($fks as $fk) {
        $clave = $fk->tabla.'.'.$fk->columna;

        expect($cubiertas[$clave] ?? false)->toBeTrue(
            "La llave foranea {$clave} no tiene indice: una FK sin indice es un recorrido secuencial en cada escritura."
        );
    }

    // 3. El indice PARCIAL de los lotes disponibles.
    $delLote = DB::select(<<<'SQL'
        select indexname, indexdef
          from pg_indexes
         where schemaname = 'public'
           and tablename = 'inventory_lots'
        SQL);

    $parcial = null;

    foreach ($delLote as $indice) {
        // El `WHERE` se escribe como `available > (0)::numeric`: el predicado
        // se busca por su FORMA, no por su texto exacto.
        if (str_contains($indice->indexdef, 'WHERE')
            && preg_match('/available\s*>\s*\(?0\)?(::numeric)?/', $indice->indexdef) === 1) {
            $parcial = $indice;
        }
    }

    expect($parcial)->not->toBeNull('inventory_lots no tiene el indice parcial de los lotes disponibles.')
        ->and($parcial->indexdef)->toContain('(product_id, store_id, received_at, id)')
        ->and($parcial->indexdef)->toMatch('/available\s*>\s*\(?0\)?/');

    // 4. Los CHECK del lote los hace cumplir el ESQUEMA: se comprueba
    //    INSERTANDO, no leyendo la definicion de la restriccion.
    $tienda = Store::create(['name' => 'Tienda de integridad']);
    $producto = Product::create([
        'code' => 'INT-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
        'name' => 'Producto de integridad',
    ]);

    $lote = [
        'product_id' => $producto->id,
        'store_id' => $tienda->id,
        'quantity_received' => '10.0000',
        'quantity_consumed' => '0.0000',
        'received_at' => now(),
    ];

    // 4a. Un consumo NEGATIVO no entra.
    expect(fn () => DB::table('inventory_lots')->insert(array_merge($lote, [
        'quantity_consumed' => '-1.0000',
    ])))->toThrow(QueryException::class, 'inventory_lots_quantity_consumed_non_negative');

    // 4b. Una disponibilidad NEGATIVA no entra: se recibe menos de lo que se
    //     consume, y el lote queda debiendo stock.
    expect(fn () => DB::table('inventory_lots')->insert(array_merge($lote, [
        'quantity_received' => '1.0000',
        'quantity_consumed' => '5.0000',
    ])))->toThrow(QueryException::class, 'inventory_lots_available_non_negative');
});
