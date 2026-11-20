<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-08 — la PANTALLA de la cola de trabajos de impresion.
 *
 * REGLA que fija: *la cola de trabajos se ve, y lo que se puede hacer con
 * cada trabajo se ve con el*.
 *
 *  - la pantalla INERTA entrega los trabajos de la tienda con su estado, su
 *    destino, sus intentos, su motivo de fallo y su MOTIVO DE REIMPRESION;
 *  - la API entrega el MISMO contrato, en JSON;
 *  - el reintento y la reimpresion se OFRECEN en los trabajos que los admiten,
 *    y el permiso decide si el boton aparece.
 */

/**
 * Quien administra la COLA de trabajos de impresion.
 */
function admin_de_la_pantalla_de_la_cola(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('cola-pantalla-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingReprint->value,
        Permission::PrintingRetry->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * La pantalla de la cola responde con su CONTRATO de props.
 */
test('test_la_pantalla_de_la_cola_de_trabajos_responde_con_su_contrato', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_pantalla_de_la_cola($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    // Un trabajo FALLIDO, con su motivo de fallo.
    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
        'attempts' => 2,
    ]);

    // Y una REIMPRESION, con su motivo.
    $reimpresionId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
        'reason' => 'reprint',
        'reprint_of_job_id' => $fallidoId,
    ]);

    $respuesta = $this->actingAs($admin)->get('/impresion/trabajos');

    $respuesta->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('printing/jobs')
            ->where('store.id', $escenario['store_id'])
            ->where('jobs', fn ($trabajos): bool => count($trabajos) === 2)
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallidoId)['status'] === 'failed')
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallidoId)['attempts'] === 2)
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallidoId)['last_error'] === 'La impresora no responde.')
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallidoId)['target_name'] === 'Destino de prueba')
            // El MOTIVO DE REIMPRESION: por que existe el trabajo.
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $reimpresionId)['reason'] === 'reprint')
            ->where('jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $reimpresionId)['reprint_of_job_id'] === $fallidoId)
        );
});

/**
 * La API de la cola responde con su CONTRATO, en JSON.
 */
test('test_la_api_de_la_cola_de_trabajos_responde_con_su_contrato', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_pantalla_de_la_cola($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
        'attempts' => 2,
    ]);

    $reimpresionId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
        'reason' => 'reprint',
        'reprint_of_job_id' => $fallidoId,
    ]);

    $respuesta = $this->actingAs($admin)->getJson('/api/print-jobs');

    $respuesta->assertOk()
        ->assertJsonPath('store.id', $escenario['store_id'])
        ->assertJsonCount(2, 'jobs');

    $trabajos = collect($respuesta->json('jobs'))->keyBy('id');

    expect((string) $trabajos[$fallidoId]['status'])->toBe('failed')
        ->and((int) $trabajos[$fallidoId]['attempts'])->toBe(2)
        ->and((string) $trabajos[$fallidoId]['last_error'])->toBe('La impresora no responde.')
        ->and((string) $trabajos[$fallidoId]['target_name'])->toBe('Destino de prueba')
        ->and($trabajos[$fallidoId]['reason'])->toBeNull()
        ->and((string) $trabajos[$reimpresionId]['reason'])->toBe('reprint')
        ->and((int) $trabajos[$reimpresionId]['reprint_of_job_id'])->toBe($fallidoId);
});

/**
 * El reintento y la reimpresion SE OFRECEN en los trabajos que los admiten.
 *
 * REGLA que fija: *un trabajo en cola no se reintenta y no se reimprime*: no
 * ha salido, y reimprimirlo duplicaria el ticket. Y un trabajo fallido admite
 * las dos cosas: se reintenta con sus bytes y se reimprime con sus bytes.
 */
test('test_el_reintento_y_la_reimpresion_se_ofrecen_en_los_trabajos_que_los_admiten', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_pantalla_de_la_cola($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $fallidoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
    ]);

    $enColaId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
    ]);

    $impresoId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $respuesta = $this->actingAs($admin)->getJson('/api/print-jobs');

    $respuesta->assertOk();

    $trabajos = collect($respuesta->json('jobs'))->keyBy('id');

    // El FALLIDO: se reintenta y se reimprime.
    expect($trabajos[$fallidoId]['can_retry'])->toBeTrue()
        ->and($trabajos[$fallidoId]['can_reprint'])->toBeTrue()
        // El EN COLA: ni lo uno ni lo otro.
        ->and($trabajos[$enColaId]['can_retry'])->toBeFalse()
        ->and($trabajos[$enColaId]['can_reprint'])->toBeFalse()
        // El IMPRESO: se reimprime (ya salio), pero no se reintenta (no fallo).
        ->and($trabajos[$impresoId]['can_retry'])->toBeFalse()
        ->and($trabajos[$impresoId]['can_reprint'])->toBeTrue();
});

/**
 * La cola es POR TIENDA: los trabajos de otra tienda no se ven.
 */
test('test_la_cola_de_trabajos_solo_muestra_los_trabajos_de_la_tienda', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_pantalla_de_la_cola($escenario['store_id']);

    $destino = PrintFixture::destino($escenario['store_id']);

    $propioId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
    ]);

    $otra = PrintFixture::contexto();

    $ajenoId = PrintFixture::trabajo($otra['store_id'], $otra['target_id'], [
        'status' => 'queued',
    ]);

    $respuesta = $this->actingAs($admin)->getJson('/api/print-jobs');

    $respuesta->assertOk()->assertJsonCount(1, 'jobs');

    expect((int) $respuesta->json('jobs.0.id'))->toBe($propioId)
        ->and((int) $respuesta->json('jobs.0.id'))->not->toBe($ajenoId);
});

/**
 * SIN SESION no se llega a la cola, y sin PERMISO tampoco.
 */
test('test_sin_sesion_o_sin_permiso_no_se_llega_a_la_cola_de_trabajos', function () {
    $escenario = PosFixture::escenario();

    $this->getJson('/api/print-jobs')->assertStatus(401);

    $this->get('/impresion/trabajos')->assertRedirect('/login');

    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-cola-'.uniqid(), [Permission::SalesCreate->value]),
        $escenario['store_id'],
    );

    $this->actingAs($cajero)->getJson('/api/print-jobs')->assertStatus(403);
});
