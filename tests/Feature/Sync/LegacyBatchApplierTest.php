<?php

use App\Jobs\ApplyLegacySyncBatch;
use App\Sync\Legacy\LegacyBatchApplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 0.8.8-T — el APLICADOR del lote: TODO o NADA, y un reenvio IDENTICO no
 * se aplica dos veces.
 *
 * REGLA que fija: *el reenvío no viola la clave primaria ni duplica efectos*.
 *
 * El terminal legacy trunca su log SOLO cuando la respuesta fue exitosa, asi
 * que una respuesta perdida provoca un REENVIO IDENTICO del mismo lote. Si el
 * maestro lo aplicara otra vez, la venta estaria duplicada y el terminal se
 * quedaria congelado en la ventana de corte (ADR-0006, hallazgo S1).
 *
 * La clave de idempotencia es (`client_id`, `payload_sha256`), y el hash es
 * del LOTE (los bytes exactos del string `data`), NO del cuerpo HTTP: el
 * terminal reenvia el mismo lote con un `nonce` y un `timestamp` nuevos.
 *
 * El MAPA se siembra aqui, no en un seeder: lo que se fija es la REGLA (la
 * traduccion sale de la FILA, nunca de un `if` por tabla), no el contenido
 * del mapa. El seeder del mapa es de otra unidad.
 *
 * El lote se aplica OPERACION A OPERACION: el cobro del lote REFERENCIA la
 * venta que la primera operacion del MISMO lote acaba de crear, y ese
 * `resolve_ref` solo resuelve si la traduccion ocurre despues de aplicar la
 * operacion anterior.
 */
beforeEach(function () {
    DB::table('legacy_translation_map')->delete();

    DB::table('legacy_translation_map')->insert([
        // La CABECERA de la venta: la tabla del legacy es `sales` aqui.
        ['legacy_table' => 'selling_info', 'legacy_column' => '*', 'new_table' => 'sales', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'invoice_id', 'new_table' => 'sales', 'new_column' => 'reference_no', 'transform' => 'rename'],
        // La TIENDA sale del CONTEXTO del lote, no de la que envio el legacy.
        ['legacy_table' => 'selling_info', 'legacy_column' => 'store_id', 'new_table' => 'sales', 'new_column' => 'store_id', 'transform' => 'inject_store'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'payment_status', 'new_table' => 'sales', 'new_column' => 'status', 'transform' => 'cast_enum:paid:paid:due:confirmed'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'created_at', 'new_table' => 'sales', 'new_column' => 'sale_date', 'transform' => 'rename'],

        // El COBRO: su `invoice_id` es un TEXTO y aqui la FK es un bigint.
        ['legacy_table' => 'payments', 'legacy_column' => '*', 'new_table' => 'sale_payments', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'payments', 'legacy_column' => 'invoice_id', 'new_table' => 'sale_payments', 'new_column' => 'sale_id', 'transform' => 'resolve_ref:selling_info'],
        ['legacy_table' => 'payments', 'legacy_column' => 'amount', 'new_table' => 'sale_payments', 'new_column' => 'amount', 'transform' => 'rename'],
    ]);
});

/**
 * El LOTE del terminal: dos operaciones, con la venta y su cobro.
 *
 * @return array<int, array<string, mixed>>
 */
function loteDelTerminal(): array
{
    return [
        [
            // La sentencia REAL del legacy (`_inc/model/invoice.php:308`).
            'sql' => 'INSERT INTO `selling_info` (`invoice_id`, `store_id`, `payment_status`, `created_at`) VALUES (?, ?, ?, ?)',
            'args' => ['12021/00000004', 1, 'paid', '2026-11-05 10:00:00'],
        ],
        [
            'sql' => 'INSERT INTO `payments` (`invoice_id`, `amount`) VALUES (?, ?)',
            'args' => ['12021/00000004', 12.5],
        ],
    ];
}

test('test_un_reenvio_identico_responde_exito_sin_aplicar_el_lote_dos_veces', function () {
    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    // Una VENTA que ya existe en el destino: la del legacy es de OTRO
    // numero, para que el `resolve_ref` del cobro NO pueda confundirse con
    // ella.
    $ventaPrevia = DB::table('sales')->insertGetId([
        'store_id' => $storeId,
        'reference_no' => '12021/00000003',
        'sale_date' => '2026-11-05',
        'status' => 'draft',
    ]);

    $data = (string) json_encode(loteDelTerminal());
    $contexto = ['store_id' => $storeId, 'business_date' => '2026-11-05'];

    $applier = app(LegacyBatchApplier::class);

    // 1. El lote se APLICA: la venta existe.
    $batchId = $applier->apply('pos-01', $data, $contexto);

    $venta = DB::table('sales')->where('reference_no', '12021/00000004')->first();

    expect($batchId)->toBeGreaterThan(0)
        ->and($venta)->not->toBeNull()
        // La TIENDA sale del CONTEXTO (la del maestro), no de la que envio
        // el legacy.
        ->and((int) $venta->store_id)->toBe($storeId)
        ->and((string) $venta->status)->toBe('paid');

    // El COBRO apunta a la venta que el lote ACABA de crear: su `resolve_ref`
    // se resolvio despues de aplicar la primera operacion.
    $cobro = DB::table('sale_payments')->where('sale_id', $venta->id)->first();

    expect($cobro)->not->toBeNull()
        ->and((int) $cobro->sale_id)->toBe((int) $venta->id)
        ->and((int) $cobro->sale_id)->not->toBe($ventaPrevia)
        // El IMPORTE viaja como TEXTO decimal: un flotante no es dinero.
        ->and((string) $cobro->amount)->toBe('12.5000');

    // La TRAZA del lote: una fila, con el hash del LOTE.
    $traza = DB::table('sync_batches')->first();

    expect(DB::table('sync_batches')->count())->toBe(1)
        ->and((string) $traza->client_id)->toBe('pos-01')
        ->and((string) $traza->payload_sha256)->toBe(hash('sha256', $data))
        ->and((int) $traza->statement_count)->toBe(2)
        ->and($traza->applied_at)->not->toBeNull();

    // 2. El REENVIO IDENTICO: el MISMO string `data`.
    $batchIdDelReenvio = $applier->apply('pos-01', $data, $contexto);

    expect($batchIdDelReenvio)->toBe($batchId)
        // La venta NO se duplica, ni el cobro, ni la traza.
        ->and(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(1)
        ->and(DB::table('sale_payments')->count())->toBe(1)
        ->and(DB::table('sync_batches')->count())->toBe(1);
});

test('test_un_lote_que_falla_a_mitad_no_deja_nada_escrito', function () {
    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    $lote = [
        [
            // VALIDA: crea la venta.
            'sql' => 'INSERT INTO `selling_info` (`invoice_id`, `store_id`, `payment_status`, `created_at`) VALUES (?, ?, ?, ?)',
            'args' => ['12021/00000004', 1, 'paid', '2026-11-05 10:00:00'],
        ],
        [
            // INVALIDA: `store_id` y `created_at` son NOT NULL en el destino
            // y esta operacion no los trae.
            'sql' => 'INSERT INTO `selling_info` (`invoice_id`) VALUES (?)',
            'args' => ['12021/00000005'],
        ],
    ];

    $data = (string) json_encode($lote);
    $contexto = ['store_id' => $storeId, 'business_date' => '2026-11-05'];

    $applier = app(LegacyBatchApplier::class);

    expect(fn () => $applier->apply('pos-01', $data, $contexto))
        ->toThrow(QueryException::class);

    // No queda NADA: ni la venta de la primera operacion, ni la traza del
    // lote. Un lote a medias es PEOR que un lote rechazado.
    expect(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(0)
        ->and(DB::table('sync_batches')->count())->toBe(0);
});

test('test_el_job_aplica_el_lote_y_un_reenvio_no_lo_aplica_dos_veces', function () {
    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    $data = (string) json_encode(loteDelTerminal());
    $contexto = ['store_id' => $storeId, 'business_date' => '2026-11-05'];

    // El JOB es la via asincrona: entrega el lote entero al aplicador.
    ApplyLegacySyncBatch::dispatch('pos-01', $data, $contexto);

    expect(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(1)
        ->and(DB::table('sync_batches')->count())->toBe(1);

    // Y el reenvio del MISMO lote no lo aplica dos veces.
    ApplyLegacySyncBatch::dispatch('pos-01', $data, $contexto);

    expect(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(1)
        ->and(DB::table('sync_batches')->count())->toBe(1);
});
