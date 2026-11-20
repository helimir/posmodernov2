<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-08 — el REINTENTO y el BARRIDO DE LEASES.
 *
 * REGLA que fija: *un trabajo fallido vuelve a la cola, y un trabajo cuyo
 * lease caduca tambien*.
 *
 *  - el REINTENTO es el reintento de la persona: un trabajo `failed` vuelve a
 *    `queued`, cuenta un intento mas y CONSERVA sus bytes: un trabajo que
 *    fallo al ESCRIBIR se reintenta con los MISMOS bytes, no con un ticket
 *    nuevo;
 *  - el BARRIDO es el que no depende de nadie: un agente que muere con un
 *    trabajo en las manos no puede dejar ese trabajo bloqueado para siempre.
 *    El lease caduca, y el trabajo vuelve a la cola;
 *  - el barrido NO HACE NADA EN BUCLE: barre una vez y sale. El bucle es del
 *    planificador (unidad F2-14).
 */

/**
 * Quien administra la COLA de trabajos de impresion.
 */
function admin_de_la_cola_del_reintento(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('cola-reintento-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingReprint->value,
        Permission::PrintingRetry->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * El reintento devuelve un trabajo FALLIDO a la cola, suma un intento y
 * conserva los bytes.
 *
 * REGLA que fija: *reintentar es volver a ENCOLAR, no volver a componer*. Los
 * bytes del ticket son los congelados: si se recomponieran, el reintento
 * imprimiría un ticket DISTINTO del que fallo.
 */
test('test_el_reintento_devuelve_un_trabajo_fallido_a_la_cola_suma_un_intento_y_conserva_los_bytes', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_del_reintento($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
        'attempts' => 1,
    ]);

    $antes = PrintJob::find($fallidoId);
    $bytesAntes = (string) $antes->payload_bytes;
    $hashAntes = (string) $antes->payload_sha256;

    $respuesta = $this->actingAs($admin)->postJson("/api/print-jobs/{$fallidoId}/reintentar");

    $respuesta->assertOk()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('attempts', 2);

    $despues = PrintJob::find($fallidoId);

    expect((string) $despues->status)->toBe('queued')
        // El intento se SUMA: este ya lo habia intentado una vez.
        ->and((int) $despues->attempts)->toBe(2)
        // Y los bytes son LOS MISMOS: no se vuelve a componer el ticket.
        ->and((string) $despues->payload_bytes)->toBe($bytesAntes)
        ->and((string) $despues->payload_sha256)->toBe($hashAntes)
        // El trabajo vuelve a la cola SIN agente y SIN lease.
        ->and($despues->agent_id)->toBeNull()
        ->and($despues->lease_expires_at)->toBeNull();
});

/**
 * Un trabajo que NO esta fallido no se puede reintentar.
 *
 * REGLA que fija: *solo se reintenta lo que fallo*. Un trabajo en cola no ha
 * fallido: reintentarlo seria meterlo dos veces en la cola.
 */
test('test_un_trabajo_en_cola_no_se_puede_reintentar', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_cola_del_reintento($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $enColaId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
    ]);

    $respuesta = $this->actingAs($admin)->postJson("/api/print-jobs/{$enColaId}/reintentar");

    $respuesta->assertStatus(422);

    $mensaje = (string) $respuesta->json('errorMsg');

    // El mensaje dice que solo se reencola un trabajo fallido.
    expect($mensaje)->toContain('queued')
        ->and($mensaje)->toContain('fallido')
        ->and($mensaje)->not->toBe('');

    // Y el trabajo NO se ha movido.
    expect((string) PrintJob::find($enColaId)->status)->toBe('queued');
});

/**
 * El BARRIDO devuelve a la cola los trabajos con el lease CADUCADO, y no toca
 * los que estan bien.
 *
 * REGLA que fija: *un trabajo con el lease caducado no queda bloqueado para
 * siempre*. Y el barrido es POR TIENDA: el trabajo vuelve a la cola de SU
 * tienda, y el de otra tienda no se toca.
 */
test('test_el_barrido_devuelve_a_la_cola_los_trabajos_con_el_lease_caducado_y_no_toca_los_que_estan_bien', function () {
    $escenario = PosFixture::escenario();

    $destino = PrintFixture::destino($escenario['store_id']);

    // El trabajo con el lease CADUCADO: el agente murio con el en las manos.
    $caducadoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'claimed',
        'lease_expires_at' => now()->subMinutes(5),
        'attempts' => 1,
    ]);

    // Y el trabajo con el lease VIVO: su agente sigue con el.
    $sanoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'claimed',
        'lease_expires_at' => now()->addMinutes(5),
    ]);

    // Y el trabajo de OTRA tienda, con su lease caducado: el barrido es del
    // SISTEMA, asi que tambien lo libera: si solo barriera las tiendas que
    // alguien visita, los trabajos de las demas quedarian bloqueados para
    // siempre, que es EXACTAMENTE el fallo que este barrido arregla.
    $otra = PrintFixture::contexto();

    $ajenoId = PrintFixture::trabajo($otra['store_id'], $otra['target_id'], [
        'status' => 'claimed',
        'lease_expires_at' => now()->subMinutes(5),
    ]);

    $this->artisan('ops:print-lease-sweep')->assertSuccessful();

    // 1. El caducado vuelve a la cola, con un intento mas.
    $caducado = PrintJob::find($caducadoId);

    expect((string) $caducado->status)->toBe('queued')
        ->and((int) $caducado->attempts)->toBe(2)
        ->and($caducado->agent_id)->toBeNull()
        ->and($caducado->lease_expires_at)->toBeNull();

    // 2. El sano NO se toca: su lease sigue vivo.
    $sano = PrintJob::find($sanoId);

    expect((string) $sano->status)->toBe('claimed')
        ->and((int) $sano->attempts)->toBe(0)
        ->and($sano->lease_expires_at)->not->toBeNull();

    // 3. Y el de la otra tienda TAMBIÉN: el barrido es del sistema.
    expect((string) PrintJob::find($ajenoId)->status)->toBe('queued');
});

/**
 * El comando BARRRA UNA VEZ Y SALE.
 *
 * REGLA que fija: *el barrido no se queda esperando*. Barre una vez, dice
 * cuantos barro y sale: el trabajo en bucle es del planificador (unidad
 * F2-14), y un bucle en un comando no se puede apagar.
 */
test('test_el_comando_barre_una_vez_y_sale_diciendo_cuanto_barro', function () {
    $escenario = PosFixture::escenario();

    $destino = PrintFixture::destino($escenario['store_id']);

    $caducadoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printing',
        'lease_expires_at' => now()->subMinutes(5),
        'attempts' => 0,
    ]);

    $codigo = Artisan::call('ops:print-lease-sweep');

    $salida = Artisan::output();

    // El trabajo volvió a la cola CON UN SOLO intento mas: si el comando
    // barriera en bucle, el intento se sumaria mas de una vez.
    $trabajo = PrintJob::find($caducadoId);

    expect((string) $trabajo->status)->toBe('queued')
        ->and((int) $trabajo->attempts)->toBe(1);

    // Y la salida DICE cuantos barro.
    expect($codigo)->toBe(0)
        ->and($salida)->toContain('Trabajos liberados')
        ->and($salida)->toContain('1');
});

/**
 * El barrido NO TOCA los trabajos TERMINADOS.
 *
 * REGLA que fija: *un trabajo impreso o cancelado no vuelve a la cola*. El
 * barrido libera lo que esta EN CURSO y se ha quedado sin lease; lo demas esta
 * en su sitio.
 */
test('test_el_barrido_no_toca_los_trabajos_terminados', function () {
    $escenario = PosFixture::escenario();

    $destino = PrintFixture::destino($escenario['store_id']);

    $impresoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $canceladoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'cancelled',
    ]);

    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
    ]);

    $this->artisan('ops:print-lease-sweep')->assertSuccessful();

    expect((string) PrintJob::find($impresoId)->status)->toBe('printed')
        ->and((string) PrintJob::find($canceladoId)->status)->toBe('cancelled')
        // Y el fallido se queda fallido: el que lo reintenta es la persona.
        ->and((string) PrintJob::find($fallidoId)->status)->toBe('failed');
});

/**
 * Sin el PERMISO de reintentar, no se reintenta.
 */
test('test_sin_el_permiso_de_reintentar_no_se_reintenta', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-reintento-'.uniqid(), [Permission::PrintingRead->value]),
        $escenario['store_id'],
    );

    $destino = PrintFixture::destino($escenario['store_id']);

    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
    ]);

    $this->actingAs($cajero)->postJson("/api/print-jobs/{$fallidoId}/reintentar")->assertStatus(403);

    expect((string) PrintJob::find($fallidoId)->status)->toBe('failed');
});
