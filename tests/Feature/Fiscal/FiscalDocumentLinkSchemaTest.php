<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.6.6 — los enlaces que quedaron abiertos, cerrados por M31.
 *
 * Regla que fija: *la venta y su comprobante fiscal estan enlazados por la
 * base de datos, y la reserva del numero sabe que venta la origino*.
 *
 * M11 declaro `sales.fiscal_document_id` SIN clave foranea y M22 difirio
 * `fiscal_documents.customer_id` a M27 "para no crear un ciclo". M27
 * resulto ser la migracion de `opening_balances`: los dos enlaces NUNCA se
 * cerraron. M31 los cierra.
 *
 * Y `fiscal_document_taxes.line_id` NO lleva clave foranea, a PROPOSITO: es
 * POLIMORFICO (una linea de venta o una linea de compra, segun el
 * `document_type`) y una clave foranea no puede apuntar a dos tablas. La
 * ausencia de FK es la decision correcta, y la columna lo declara.
 */

/**
 * Las claves foraneas de una tabla, tal como las declara PostgreSQL.
 *
 * @return array<string, string> Nombre del constraint => su definicion.
 */
function clavesForaneasM31(string $table): array
{
    $rows = DB::select(
        'SELECT c.conname AS name, pg_get_constraintdef(c.oid) AS definition
           FROM pg_constraint c
           JOIN pg_class t ON t.oid = c.conrelid
           JOIN pg_namespace n ON n.oid = t.relnamespace
          WHERE c.contype = \'f\'
            AND t.relname = ?
            AND n.nspname = \'public\'',
        [$table]
    );

    $mapa = [];

    foreach ($rows as $row) {
        $mapa[$row->name] = $row->definition;
    }

    return $mapa;
}

/**
 * El comentario que la columna lleva en el catalogo de PostgreSQL.
 */
function comentarioColumnaM31(string $table, string $column): ?string
{
    $row = DB::selectOne(
        'SELECT col_description(a.attrelid, a.attnum) AS comment
           FROM pg_attribute a
           JOIN pg_class t ON t.oid = a.attrelid
           JOIN pg_namespace n ON n.oid = t.relnamespace
          WHERE t.relname = ?
            AND n.nspname = \'public\'
            AND a.attname = ?',
        [$table, $column]
    );

    return $row === null ? null : $row->comment;
}

test('test_la_venta_apunta_a_su_documento_fiscal_con_clave_foranea_real', function () {
    $claves = clavesForaneasM31('sales');

    // La FK que M11 difirio y nunca se cerro.
    $deLaVenta = array_values(array_filter(
        $claves,
        static fn (string $definicion): bool => str_contains($definicion, 'fiscal_document_id')
    ));

    expect($deLaVenta)->toHaveCount(1)
        ->and($deLaVenta[0])->toContain('REFERENCES fiscal_documents(id)');

    // Y el documento fiscal apunta al cliente con una FK real.
    $delDocumento = array_values(array_filter(
        clavesForaneasM31('fiscal_documents'),
        static fn (string $definicion): bool => str_contains($definicion, 'customer_id')
    ));

    expect($delDocumento)->toHaveCount(1)
        ->and($delDocumento[0])->toContain('REFERENCES customers(id)');
});

test('test_la_reserva_del_documento_sabe_que_hecho_la_origino', function () {
    $columnas = collect(Schema::getColumns('fiscal_document_reservations'))->keyBy('name');

    // El par que enlaza la reserva con el hecho: el MISMO patron que
    // `ledger_entries.reference_type` / `reference_id`.
    expect($columnas)->toHaveKey('reference_type')
        ->and($columnas['reference_type']['type'])->toBe('character varying(50)')
        ->and($columnas['reference_type']['nullable'])->toBeTrue()
        ->and($columnas)->toHaveKey('reference_id')
        ->and($columnas['reference_id']['type'])->toBe('bigint')
        ->and($columnas['reference_id']['nullable'])->toBeTrue();

    // Y el indice que hace que esa busqueda sea la del camino caliente: el
    // reintento busca la reserva por (`reference_type`, `reference_id`).
    $indices = DB::select(
        'SELECT indexname AS name, indexdef AS definition
           FROM pg_indexes
          WHERE tablename = \'fiscal_document_reservations\'
            AND schemaname = \'public\''
    );

    $delPar = array_values(array_filter(
        $indices,
        static fn (object $indice): bool => str_contains($indice->definition, '(reference_type, reference_id)')
    ));

    expect($delPar)->toHaveCount(1);
});

test('test_la_linea_del_impuesto_no_lleva_clave_foranea_porque_es_polimorfica', function () {
    // `fiscal_document_taxes.line_id` apunta a `sale_items` en una venta o a
    // `purchase_items` en una compra: UNA FK no puede apuntar a dos tablas.
    $claves = clavesForaneasM31('fiscal_document_taxes');

    $deLaLinea = array_values(array_filter(
        $claves,
        static fn (string $definicion): bool => str_contains($definicion, 'line_id')
    ));

    expect($deLaLinea)->toBe([]);

    // Y la columna lo DECLARA, para que la ausencia de FK no se lea como un
    // olvido.
    $comentario = comentarioColumnaM31('fiscal_document_taxes', 'line_id');

    expect($comentario)->not->toBeNull()
        ->and($comentario)->toContain('sale_items')
        ->and($comentario)->toContain('purchase_items');
});
