<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\User;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Support\PrintSocketServer;

/**
 * F2-07 — la PRUEBA de la conexion de un destino de impresion.
 *
 * REGLA que fija: *quien puede escribir en un destino lo decide el
 * TRANSPORTE, y el que conecta tambien*.
 *
 *  - `server_socket`: el SERVIDOR abre la conexion y la cierra. No escribe un
 *    ticket de verdad: aqui se PRUEBA, no se imprime;
 *  - `lan_agent`: NO se puede probar desde el servidor, porque el que
 *    conecta es el agente. La respuesta lo dice, con el motivo;
 *  - `browser`: NO APLICA, porque no hay destino fisico: el navegador es el
 *    destino.
 *
 * Y ADEMAS SE AVISA DEL ANCHO INSUFICIENTE: la conexion puede estar bien y
 * el ticket no caber. Eso es el aviso temprano del "ticket mudo".
 */

/**
 * El ADMINISTRADOR de la impresion: una persona con los permisos de esta
 * unidad y con su vinculo por la tienda.
 */
function admin_de_la_impresion(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('impresion-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingCreate->value,
        Permission::PrintingUpdate->value,
        Permission::PrintingTest->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * Un DESTINO de impresion, con su impresora del catalogo.
 *
 * @param  array<string, mixed>  $atributos
 */
function destino_para_probar(int $storeId, array $atributos = []): PrintTarget
{
    $impresora = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    return PrintTarget::create(array_merge([
        'printer_id' => $impresora->id,
        'store_id' => $storeId,
        'name' => 'Destino de prueba '.uniqid('DST', true),
        'transport' => 'server_socket',
        'endpoint' => 'tcp://127.0.0.1:9100',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ], $atributos));
}

/**
 * Un destino a un puerto donde NO hay nada escuchando responde que no se pudo
 * conectar, CON SU MOTIVO.
 *
 * REGLA que fija: *la conexion que no se abre es un RESULTADO con su
 * motivo*. La persona que mira la pantalla tiene que saber SI fallo y POR
 * QUE fallo.
 */
test('test_un_destino_a_un_puerto_sin_nada_escuchando_responde_que_no_se_pudo_conectar', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_impresion($escenario['store_id']);

    // El puerto 9100 es el que admite el esquema de destinos, y aqui no hay
    // nada escuchando en el: ese es el caso que se prueba.
    $destino = destino_para_probar($escenario['store_id']);

    $respuesta = $this->actingAs($admin)
        ->postJson('/api/impresion/destinos/'.$destino->id.'/probar');

    $respuesta->assertOk()->assertJsonPath('status', 'failed');

    expect((string) $respuesta->json('message'))->toContain('No se pudo conectar')
        ->and((string) $respuesta->json('reason'))->not->toBe('');
});

/**
 * La prueba de conexion de una impresora de RED de verdad responde que la
 * impresora respondio.
 *
 * REGLA que fija: *la prueba ABRE la conexion y la CIERRA*. No escribe un
 * ticket: el papel no se llena con una prueba, y el ticket que el cajero no
 * pidio es un ticket que nadie quiere.
 */
test('test_el_test_de_conexion_de_un_socket_de_prueba_responde_que_la_impresora_respondio', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_impresion($escenario['store_id']);

    // La impresora de PRUEBA: un socket de verdad, en el puerto que admite el
    // esquema de destinos.
    $impresora = PrintSocketServer::start();

    try {
        $destino = destino_para_probar($escenario['store_id'], [
            'endpoint' => $impresora->endpoint(),
        ]);

        $respuesta = $this->actingAs($admin)
            ->postJson('/api/impresion/destinos/'.$destino->id.'/probar');

        $respuesta->assertOk()->assertJsonPath('status', 'connected');

        expect((string) $respuesta->json('message'))->toContain('La impresora respondio');

        // Y la prueba NO escribe nada: el ticket lo pide el cajero.
        expect(DB::table('print_jobs')->count())->toBe(0);
    } finally {
        $impresora->stop();
    }
});

/**
 * Un destino del AGENTE LAN no se puede probar desde el servidor.
 *
 * REGLA que fija: *el que conecta es el agente*. El servidor solo encola el
 * trabajo, asi que no puede abrir la conexion de la impresora: la respuesta
 * lo dice, con el motivo.
 */
test('test_un_destino_del_agente_lan_no_se_puede_probar_desde_el_servidor', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_impresion($escenario['store_id']);

    $destino = destino_para_probar($escenario['store_id'], [
        'transport' => 'lan_agent',
    ]);

    $respuesta = $this->actingAs($admin)
        ->postJson('/api/impresion/destinos/'.$destino->id.'/probar');

    $respuesta->assertOk()->assertJsonPath('status', 'not_testable');

    expect((string) $respuesta->json('message'))->toContain('agente LAN')
        ->and((string) $respuesta->json('message'))->toContain('el que conecta es el agente');
});

/**
 * Un destino del NAVEGADOR no aplica a la prueba de conexion.
 *
 * REGLA que fija: *el navegador ES el destino*. No hay impresora detras, y
 * por eso no hay conexion que probar.
 */
test('test_un_destino_de_navegador_no_aplica_al_test_de_conexion', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_impresion($escenario['store_id']);

    $destino = destino_para_probar($escenario['store_id'], [
        'transport' => 'browser',
        'endpoint' => null,
    ]);

    $respuesta = $this->actingAs($admin)
        ->postJson('/api/impresion/destinos/'.$destino->id.'/probar');

    $respuesta->assertOk()->assertJsonPath('status', 'not_applicable');

    expect((string) $respuesta->json('message'))->toContain('browser')
        ->and((string) $respuesta->json('message'))->toContain('no hay conexion que probar');
});

/**
 * La prueba AVISA del ancho insuficiente, aunque la conexion este bien.
 *
 * REGLA que fija: *la conexion puede estar en su sitio y el ticket no
 * caber*. Con el ancho declarado y el nombre de la impresora hay lineas que
 * no caben, y eso es el aviso temprano del "ticket mudo": se ve antes de que
 * el cajero cobre, y no despues, en el papel.
 */
test('test_el_test_de_conexion_avisa_del_ancho_insuficiente', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_la_impresion($escenario['store_id']);

    // El ancho es de 16 caracteres, y el nombre de la impresora del
    // catalogo es mas largo: no cabe.
    $destino = destino_para_probar($escenario['store_id'], [
        'char_per_line' => 16,
    ]);

    $respuesta = $this->actingAs($admin)
        ->postJson('/api/impresion/destinos/'.$destino->id.'/probar');

    $respuesta->assertOk();

    $avisos = $respuesta->json('warnings');

    expect($avisos)->toBeArray()->not->toBeEmpty()
        ->and((string) $avisos[0])->toContain('16')
        ->and((string) $avisos[0])->toContain('Impresora de la tienda')
        ->and((string) $avisos[0])->toContain('no cabe');
});

/**
 * Sin el permiso de PROBAR, la conexion no se prueba.
 *
 * REGLA que fija: *abrir una conexion a una impresora es una accion con
 * permiso*. Ver la configuracion no da derecho a tocar la red de la tienda.
 */
test('test_sin_el_permiso_de_probar_la_conexion_no_se_prueba', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $admin = PermissionFixture::usuario(
        PermissionFixture::rol('impresion-sin-probar-'.uniqid(), [
            Permission::PrintingRead->value,
            Permission::PrintingCreate->value,
        ]),
        $escenario['store_id'],
    );

    $destino = destino_para_probar($escenario['store_id']);

    $this->actingAs($admin)
        ->postJson('/api/impresion/destinos/'.$destino->id.'/probar')
        ->assertStatus(403);
});
