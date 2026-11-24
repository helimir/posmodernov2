<?php

use App\Models\PrintJob;
use App\Models\Role;
use App\Print\PrintJobReprinter;
use App\Print\PrintJobStateMachine;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F3-03 (segunda parte) — los TICKETS DEL CAJERO.
 *
 * REGLA que fija este fichero: *el cajero ve SUS trabajos de impresion, y
 * solo los suyos*.
 *
 * Hoy el cajero, tras cobrar, ve en la caja que el ticket esta "Encolado" o
 * "No encolado" con su motivo, y no puede mirar DESPUES: la cola de trabajos
 * esta detras de `printing.read`, que el cajero no tiene. O sea: el cajero
 * nunca sabe si su papel salio.
 *
 * Y LA PANTALLA ES SUYA, no la de los demas: los trabajos que el mismo creo y
 * los que NO TIENEN ACTOR (los del sistema). Nunca los de otro cajero, y
 * nunca los de otra tienda.
 *
 * Y NO HAY BOTON DE REIMPRESION: el dato NO distingue "el papel no salio" de
 * "el papel salio y el reporte fallo". Un boton que puede duplicar un
 * ticket es peor que un boton que no existe. La pantalla ensena el estado y
 * el motivo, y nada mas.
 */

/**
 * El ESCENARIO: la tienda de la caja, su destino de impresion, dos cajeros y
 * los trabajos de cada uno.
 *
 * Y EL CAJERO ES EL ROL REAL: el del sembrador, con su matriz real. Un rol
 * inventado aqui probaria el permiso, no el CONTRATO del cajero.
 *
 * @return array<string, mixed>
 */
function escenario_de_tickets_del_cajero(): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $destino = PrintFixture::destino($escenario['store_id'], 'lan_agent', [
        'name' => 'Caja de la tienda',
    ]);

    $rol = Role::query()->where('slug', PermissionSeeder::SLUG_CASHIER)->first();

    // El rol del cajero es parte del CONTRATO: sin el, la matriz no dice nada.
    expect($rol)->not->toBeNull();

    $cajero = PermissionFixture::usuario($rol, $escenario['store_id']);
    $companero = PermissionFixture::usuario($rol, $escenario['store_id']);

    // El TRABAJO DEL CAJERO: el suyo, con su actor.
    $mio = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'created_by' => $cajero->id,
        'status' => 'printed',
    ]);

    // El TRABAJO DEL SISTEMA: el que NO tiene actor.
    $delSistema = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'created_by' => null,
        'status' => 'queued',
    ]);

    // El TRABAJO DEL COMPAÑERO: el de otro cajero de la MISMA tienda.
    $delCompanero = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'created_by' => $companero->id,
        'status' => 'queued',
    ]);

    // Y el trabajo de OTRA TIENDA: no existe para quien pregunta.
    $otra = PrintFixture::contexto();

    $deOtraTienda = PrintFixture::trabajo($otra['store_id'], $otra['target_id'], [
        'created_by' => $cajero->id,
    ]);

    return [
        'store_id' => $escenario['store_id'],
        'target_id' => $destino['target_id'],
        'cajero' => $cajero,
        'companero' => $companero,
        'mio' => $mio,
        'del_sistema' => $delSistema,
        'del_companero' => $delCompanero,
        'de_otra_tienda' => $deOtraTienda,
    ];
}

/**
 * Los IDENTIFICADORES de los trabajos que ve el cajero.
 *
 * @return list<int>
 */
function trabajos_de_la_respuesta(mixed $respuesta): array
{
    return array_map('intval', array_column($respuesta->json('jobs') ?? [], 'id'));
}

/**
 * El cajero ve SUS trabajos y los del SISTEMA, y NUNCA los de otro cajero.
 *
 * REGLA que fija: *la pantalla del cajero es de SUS trabajos*. Los que el
 * mismo creo (`created_by` = el usuario de la sesion) y los que no tienen
 * actor, que son los del sistema. El trabajo de otro cajero NO existe para
 * quien pregunta: no es que se oculte, es que no se sirve.
 */
test('test_el_cajero_ve_sus_trabajos_y_los_del_sistema_y_nunca_los_de_otro_cajero', function () {
    $escenario = escenario_de_tickets_del_cajero();

    $respuesta = $this->actingAs($escenario['cajero'])
        ->getJson('/api/impresion/mis-trabajos');

    $respuesta->assertOk();

    $ids = trabajos_de_la_respuesta($respuesta);

    // LOS SUYOS y los del sistema: estan.
    expect($ids)->toContain($escenario['mio'])
        ->and($ids)->toContain($escenario['del_sistema']);

    // El del COMPAÑERO: no.
    expect($ids)->not->toContain($escenario['del_companero']);

    // Y el COMPANERO, mirandolo desde su propia pantalla, ve lo suyo: la
    // respuesta NO es la misma para los dos.
    $delCompanero = $this->actingAs($escenario['companero'])
        ->getJson('/api/impresion/mis-trabajos');

    $delCompanero->assertOk();

    $idsDelCompanero = trabajos_de_la_respuesta($delCompanero);

    expect($idsDelCompanero)->toContain($escenario['del_companero'])
        ->and($idsDelCompanero)->not->toContain($escenario['mio']);
});

/**
 * La respuesta NO sale de la tienda de la sesion.
 *
 * REGLA que fija: *la tienda se resuelve del CONTEXTO de la sesion, nunca del
 * cuerpo de la peticion*. Un trabajo de otra tienda no se sirve: no es que se
 * oculte, es que no existe para quien pregunta.
 */
test('test_los_trabajos_del_cajero_no_salen_de_su_tienda', function () {
    $escenario = escenario_de_tickets_del_cajero();

    $respuesta = $this->actingAs($escenario['cajero'])
        ->getJson('/api/impresion/mis-trabajos');

    $respuesta->assertOk();

    // El trabajo de la OTRA tienda no esta, aunque su actor sea el mismo
    // cajero: el actor no abre la puerta de otra tienda.
    expect(trabajos_de_la_respuesta($respuesta))->not->toContain($escenario['de_otra_tienda']);

    // Y la respuesta declara la TIENDA del contexto.
    expect((int) $respuesta->json('store.id'))->toBe($escenario['store_id']);

    // Y todos los trabajos que trae son de esa tienda.
    $otrasTiendas = DB::table('print_jobs')
        ->whereIn('id', trabajos_de_la_respuesta($respuesta))
        ->where('store_id', '!=', $escenario['store_id'])
        ->count();

    expect((int) $otrasTiendas)->toBe(0);
});

/**
 * La pantalla tiene SU permiso, y el cajero NO gana la cola general.
 *
 * REGLA que fija: *el cajero ve SUS tickets, no los de sus compañeros*. El
 * permiso de la pantalla del cajero es `printing.own`, y el de la cola
 * general sigue siendo `printing.read`: el cajero no lo tiene, y sin el no
 * abre ni la cola ni la configuracion de impresion.
 */
test('test_la_pantalla_de_los_tickets_del_cajero_esta_protegida_y_el_cajero_no_abre_la_cola_general', function () {
    $escenario = escenario_de_tickets_del_cajero();

    $cajero = $escenario['cajero'];

    // El cajero tiene el permiso de SU pantalla, y NO el de la cola general.
    expect($cajero->can('printing.own'))->toBeTrue()
        ->and($cajero->can('printing.read'))->toBeFalse();

    // Y ABRE la suya.
    $this->actingAs($cajero)->get('/impresion/mis-trabajos')->assertOk();

    // Y NO abre la cola general: esa sigue siendo del operador.
    $this->actingAs($cajero)->get('/impresion/trabajos')->assertForbidden();
    $this->actingAs($cajero)->getJson('/api/print-jobs')->assertForbidden();

    // Y un rol SIN el permiso no llega a ninguna de las dos.
    $sinPermiso = PermissionFixture::rol('sin-tickets-'.uniqid());

    $otro = PermissionFixture::usuario($sinPermiso, $escenario['store_id']);

    $this->actingAs($otro)->get('/impresion/mis-trabajos')->assertForbidden();
    $this->actingAs($otro)->getJson('/api/impresion/mis-trabajos')->assertForbidden();
});

/**
 * La pantalla ENSENA el estado, el destino, el numero y el motivo del fallo.
 *
 * REGLA que fija: *la pantalla del cajero ENSEÑA el estado del papel y, si
 * fallo, el motivo*. Un trabajo fallido sin motivo es un trabajo que el cajero
 * no puede explicar al cliente ni al encargado.
 */
test('test_la_pantalla_de_los_tickets_del_cajero_enseña_el_estado_el_destino_y_el_motivo_del_fallo', function () {
    $escenario = escenario_de_tickets_del_cajero();

    // Un trabajo FALLIDO, con su motivo.
    $fallido = PrintFixture::trabajo($escenario['store_id'], $escenario['target_id'], [
        'created_by' => $escenario['cajero']->id,
        'status' => 'failed',
        'last_error' => 'La impresora no acepto todos los bytes del ticket.',
    ]);

    $this->actingAs($escenario['cajero'])
        ->get('/impresion/mis-trabajos')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('printing/own-jobs')
            ->where('store.id', $escenario['store_id'])
            ->has('jobs', 3)
            // El ULTIMO creado va primero: el fallo es lo que el cajero mira.
            ->where('jobs.0.id', $fallido)
            ->where('jobs.0.status', 'failed')
            ->where('jobs.0.target_name', 'Caja de la tienda')
            ->where('jobs.0.last_error', 'La impresora no acepto todos los bytes del ticket.')
            ->has('jobs.0.created_at')
            ->etc());
});

/**
 * El DATO no distingue "el papel no salio" de "el papel salio y el reporte
 * fallo".
 *
 * REGLA que fija: *el estado del trabajo dice COMO termino, no SI el papel
 * salio*. Los dos fallos dejan EXACTAMENTE la misma fila: la maquina de
 * estados borra el origen del fallo (de `claimed` y de `printing` sale igual
 * `failed`), y lo unico que queda es un `last_error` de texto libre que
 * nadie interpreta.
 *
 * Y por eso el boton de reimprimir NO se pone: el dominio lo permitiria
 * (`PrintJobReprinter` reimprime un trabajo fallido), pero reimprimir un
 * trabajo fallido puede DUPLICAR un ticket que SI salio.
 */
test('test_un_trabajo_fallido_no_dice_si_el_papel_salio', function () {
    $escenario = escenario_de_tickets_del_cajero();

    $agente = PrintFixture::agente($escenario['store_id']);

    $machine = app(PrintJobStateMachine::class);

    // 1. El trabajo que NO LLEGO a la impresora: fallo ANTES de escribir.
    $sinPapel = PrintFixture::trabajo($escenario['store_id'], $escenario['target_id'], [
        'created_by' => $escenario['cajero']->id,
    ]);

    $machine->claim($sinPapel, $agente['agent_id'], 60);
    $machine->markFailed($sinPapel, 'La impresora no acepto todos los bytes del ticket.');

    // 2. El trabajo que SI LLEGO y el reporte fallo: fallo DESPUES de
    //    imprimir.
    $conPapel = PrintFixture::trabajo($escenario['store_id'], $escenario['target_id'], [
        'created_by' => $escenario['cajero']->id,
    ]);

    $machine->claim($conPapel, $agente['agent_id'], 60);
    $machine->markPrinting($conPapel);
    $machine->markFailed($conPapel, 'La impresora no acepto todos los bytes del ticket.');

    // 3. Las dos filas son IGUALES: no hay ningun campo que diga si el papel
    //    salio. Solo difieren en su identidad y en el reloj.
    //
    //    La fila se lee con el MODELO, y no con la consulta a pelo: el
    //    `bytea` vuelve como un flujo, y dos flujos nunca son el mismo flujo.
    $comparable = static function (int $id): array {
        $fila = PrintJob::find($id)->toArray();

        unset($fila['id'], $fila['created_at'], $fila['updated_at'], $fila['lease_expires_at']);

        return $fila;
    };

    expect($comparable($sinPapel))->toBe($comparable($conPapel));

    // 4. Y el dominio PERMITIRIA reimprimir el fallido: la trampa es real, y
    //    por eso la pantalla no la ofrece.
    $nuevo = app(PrintJobReprinter::class)->reprint($conPapel);

    expect($nuevo)->toBeInt()->toBeGreaterThan(0);
});

/**
 * La pantalla del cajero NO tiene boton de reimprimir ni de reintento.
 *
 * REGLA que fija: *la pantalla del cajero ES DE LECTURA*. Reimprimir un
 * trabajo fallido puede duplicar un ticket que si salio, y reintentarlo
 * puede sacar el papel otra vez: las dos cosas las decide el operador, con
 * el dato de la cola general, que si distingue el destino del trabajo.
 */
test('test_los_tickets_del_cajero_no_tienen_boton_de_reimprimir_ni_de_reintentar', function () {
    $pantalla = base_path('resources/js/pages/printing/own-jobs.tsx');

    expect(file_exists($pantalla))->toBeTrue('La pantalla de los tickets del cajero no existe.');

    $contenido = (string) file_get_contents($pantalla);

    // La pantalla NO ESCRIBE: ni una peticion, ni un permiso de escritura.
    expect($contenido)->not->toContain('postJson')
        ->and($contenido)->not->toContain("can('printing.reprint')")
        ->and($contenido)->not->toContain("can('printing.retry')")
        ->and($contenido)->not->toContain('/reimprimir')
        ->and($contenido)->not->toContain('/reintentar');
});

/**
 * La caja ENLAZA a la pantalla del cajero, no a la cola general.
 *
 * REGLA que fija: *el enlace de la caja lleva a donde el cajero puede ir*.
 * La cola general esta detras de `printing.read`, que el cajero no tiene: un
 * enlace ahi le daria un 403. El enlace del cajero va a SU pantalla, y el de
 * la cola general se queda para quien la puede abrir.
 */
test('test_el_cobro_enlaza_a_los_tickets_del_cajero_y_no_a_la_cola_general', function () {
    $caja = base_path('resources/js/pages/pos/index.tsx');

    expect(file_exists($caja))->toBeTrue('La pantalla de la caja no existe.');

    $contenido = (string) file_get_contents($caja);

    // 1. El enlace del CAJERO: a su pantalla, con SU permiso.
    expect($contenido)->toContain('/impresion/mis-trabajos')
        ->and($contenido)->toContain("can('printing.own')");

    // 2. Y el de la cola general se queda, con el permiso de la cola general.
    expect($contenido)->toContain('/impresion/trabajos')
        ->and($contenido)->toContain("can('printing.read')");
});
