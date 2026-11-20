<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-08 — la REIMPRESION de un ticket, por HTTP.
 *
 * REGLA que fija: *una reimpresion reimprime los MISMOS bytes: no se vuelve a
 * componer el ticket*.
 *
 * Los bytes CONGELADOS son la verdad del ticket: son lo que se recibio en el
 * acuse. Si se recompone, el ticket puede salir DISTINTO del que se emitio, y
 * un comprobante que cambia es un comprobante que miente.
 *
 *  - la reimpresion es un trabajo NUEVO, con los MISMOS bytes y el mismo hash,
 *    con `reason = 'reprint'` y el puntero al original;
 *  - reimprimir NO reserva nada: el comprobante fiscal ya esta emitido y no se
 *    vuelve a emitir;
 *  - solo se reimprime un trabajo YA IMPRESO o FALLIDO: un trabajo en cola
 *    todavia no ha salido, y reimprimirlo duplicaria el ticket;
 *  - un trabajo de OTRA TIENDA no existe: 404, no 403.
 */

/**
 * Quien administra la COLA de trabajos de impresion.
 */
function admin_de_la_cola_de_impresion(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('cola-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingReprint->value,
        Permission::PrintingRetry->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * Un trabajo de impresion con bytes PROPIOS: unos que un render jamas
 * produciria.
 *
 * REGLA que fija este helper: *los bytes de prueba tienen que ser
 * irreproducibles*. Si los bytes del original fueran los que sale de un
 * render, una reimpresion que compusiera el ticket de nuevo pasaria el
 * test, y el test que tiene que fallar no fallaria.
 *
 * @param  array<string, mixed>  $atributos
 */
function trabajo_con_bytes_irreproducibles(int $storeId, int $targetId, array $atributos = []): int
{
    $bytes = "\x1b\x40ORIGEN-9F3A2B7C\x1d\x56\x41\x03";

    return (int) DB::table('print_jobs')->insertGetId(array_merge([
        'store_id' => $storeId,
        'print_target_id' => $targetId,
        'agent_id' => null,
        'status' => 'printed',
        'payload' => json_encode([
            'title' => 'TICKET',
            'lines' => [['text' => 'TICKET DE PRUEBA']],
            'qr_content' => null,
        ], JSON_UNESCAPED_UNICODE),
        // El `bytea` se escribe en hexadecimal: el constructor de consultas
        // trunca cualquier cadena con un byte nulo.
        'payload_bytes' => DB::raw("decode('".bin2hex($bytes)."', 'hex')"),
        'payload_sha256' => hash('sha256', $bytes),
        'reason' => null,
        'reprint_of_job_id' => null,
        'lease_expires_at' => null,
        'attempts' => 0,
        'last_error' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $atributos));
}

/**
 * La reimpresion crea un trabajo NUEVO con los MISMOS bytes y el mismo hash.
 */
test('test_la_reimpresion_crea_un_trabajo_nuevo_con_los_mismos_bytes_y_el_mismo_hash', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_de_impresion($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $originalId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $original = PrintJob::find($originalId);

    $respuesta = $this->actingAs($admin)->postJson("/api/print-jobs/{$originalId}/reimprimir");

    $respuesta->assertCreated()
        ->assertJsonPath('reason', 'reprint')
        ->assertJsonPath('reprint_of_job_id', $originalId)
        ->assertJsonPath('status', 'queued');

    $nuevoId = (int) $respuesta->json('id');
    $nuevo = PrintJob::find($nuevoId);

    expect($nuevo)->not->toBeNull('La reimpresion no creo ningun trabajo.')
        ->and($nuevoId)->not->toBe($originalId)
        ->and((string) $nuevo->reason)->toBe('reprint')
        ->and((int) $nuevo->reprint_of_job_id)->toBe($originalId)
        ->and((string) $nuevo->status)->toBe('queued')
        // Un trabajo NUEVO: no ha tenido intentos, y nadie lo tiene.
        ->and((int) $nuevo->attempts)->toBe(0)
        ->and($nuevo->agent_id)->toBeNull()
        // Los MISMOS bytes y el MISMOS hash: son los mismos bytes.
        ->and((string) $nuevo->payload_bytes)->toBe((string) $original->payload_bytes)
        ->and((string) $nuevo->payload_sha256)->toBe((string) $original->payload_sha256)
        // Y el destino es el MISMO: se reimprime en la misma impresora.
        ->and((int) $nuevo->print_target_id)->toBe((int) $original->print_target_id)
        ->and((int) $nuevo->store_id)->toBe((int) $original->store_id);

    // Y el ORIGINAL no se toca: sigue impreso y con sus bytes.
    $original->refresh();

    expect((string) $original->status)->toBe('printed')
        ->and((int) $original->attempts)->toBe(0)
        ->and($original->reason)->toBeNull()
        ->and((int) $original->reprint_of_job_id)->toBe(0);
});

/**
 * La reimpresion NO reserva nada: el comprobante fiscal ya esta emitido.
 *
 * REGLA que fija: *reimprimir no es reemitir*. Una reimpresion es un trabajo
 * de impresion nuevo contra el MISMO documento: no consume correlativo, no
 * crea reservas y no toca el documento emitido.
 */
test('test_la_reimpresion_no_reserva_nada_y_no_vuelve_a_emitir_el_comprobante', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_de_impresion($escenario['store_id']);

    // El COMPROBANTE EMITIDO, con su numero.
    $documentoId = PermissionFixture::documento($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $originalId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $documentosAntes = DB::table('fiscal_documents')->count();
    $reservasAntes = DB::table('fiscal_document_reservations')->count();

    $this->actingAs($admin)->postJson("/api/print-jobs/{$originalId}/reimprimir")->assertCreated();

    // 1. El documento fiscal NO SE TOCA.
    $documento = DB::table('fiscal_documents')->where('id', $documentoId)->first();

    expect((string) $documento->status)->toBe('issued')
        ->and((int) $documento->number)->toBe(9001)
        // 2. Y CERO documentos y CERO reservas nuevas.
        ->and(DB::table('fiscal_documents')->count())->toBe($documentosAntes)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe($reservasAntes);
});

/**
 * Un trabajo EN COLA no se puede reimprimir: todavia no ha salido.
 *
 * REGLA que fija: *solo se reimprime lo que ya salio*. Un trabajo en cola puede
 * salir tal cual, con sus propios bytes: reimprimirlo duplicaria el ticket.
 */
test('test_no_se_puede_reimprimir_un_trabajo_en_cola', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_de_impresion($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $enColaId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
    ]);

    $respuesta = $this->actingAs($admin)->postJson("/api/print-jobs/{$enColaId}/reimprimir");

    $respuesta->assertStatus(422);

    $mensaje = (string) $respuesta->json('errorMsg');

    // El mensaje DICE que el trabajo todavia no ha salido.
    expect($mensaje)->toContain('queued')
        ->and($mensaje)->toContain('IMPRESO')
        ->and($mensaje)->toContain('FALLIDO')
        ->and($mensaje)->not->toBe('');

    // Y NO se ha creado ningun trabajo: una reimpresion rechazada no deja
    // rastro.
    expect(DB::table('print_jobs')->count())->toBe(1);
});

/**
 * Un trabajo de OTRA TIENDA no existe: 404, no 403.
 *
 * REGLA que fija: *a un cajero no se le dice que el trabajo existe*. Un 403
 * confirmaria que el trabajo esta ahi, en otra tienda; un 404 no dice nada.
 */
test('test_no_se_puede_reimprimir_un_trabajo_de_otra_tienda', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_de_impresion($escenario['store_id']);

    // El trabajo de OTRA tienda.
    $otra = PrintFixture::contexto();

    $ajenoId = PrintFixture::trabajo($otra['store_id'], $otra['target_id'], [
        'status' => 'printed',
    ]);

    $respuesta = $this->actingAs($admin)->postJson("/api/print-jobs/{$ajenoId}/reimprimir");

    $respuesta->assertNotFound();

    // Y no se ha creado ningun trabajo en ninguna tienda.
    expect(DB::table('print_jobs')->count())->toBe(1);
});

/**
 * La reimpresion NO vuelve a componer el ticket: reutiliza los bytes.
 *
 * REGLA que fija: *los bytes congelados son la verdad*. Esta es la prueba que
 * falla si la reimpresion compusiera el ticket de nuevo: los bytes del
 * original son IRREPRODUCIBLES (llevan una marca que ningun render sabe
 * escribir), asi que un ticket recompuesto NO puede coincidir con ellos.
 */
test('test_la_reimpresion_reutiliza_los_bytes_congelados_y_no_vuelve_a_componer_el_ticket', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_de_impresion($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $originalId = trabajo_con_bytes_irreproducibles($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $original = PrintJob::find($originalId);

    $bytesCongelados = (string) $original->payload_bytes;

    $this->actingAs($admin)->postJson("/api/print-jobs/{$originalId}/reimprimir")->assertCreated();

    $nuevo = PrintJob::query()->where('reprint_of_job_id', $originalId)->first();

    expect($nuevo)->not->toBeNull()
        // Los bytes son LOS MISMOS, con la marca irreproducible dentro.
        ->and((string) $nuevo->payload_bytes)->toBe($bytesCongelados)
        ->and((string) $nuevo->payload_bytes)->toContain('ORIGEN-9F3A2B7C')
        // Y el hash es el hash de ESOS bytes: no el de un ticket recompuesto.
        ->and((string) $nuevo->payload_sha256)->toBe(hash('sha256', $bytesCongelados))
        ->and((string) $nuevo->payload_sha256)->toBe((string) $original->payload_sha256);
});

/**
 * Sin el PERMISO de reimprimir, no se reimprime.
 *
 * REGLA que fija: *el permiso es la ley*. Quien puede ver la cola no puede
 * reimprimir: son dos cosas distintas, y por eso son dos permisos.
 */
test('test_sin_el_permiso_de_reimprimir_no_se_reimprime', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-'.uniqid(), [Permission::PrintingRead->value]),
        $escenario['store_id'],
    );

    $destino = PrintFixture::destino($escenario['store_id']);

    $originalId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $this->actingAs($cajero)->postJson("/api/print-jobs/{$originalId}/reimprimir")->assertStatus(403);

    expect(DB::table('print_jobs')->count())->toBe(1);
});

/**
 * SIN SESION no se reimprime.
 */
test('test_sin_sesion_no_se_reimprime', function () {
    $this->postJson('/api/print-jobs/1/reimprimir')->assertStatus(401);
});
