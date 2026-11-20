<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-07 — la ADMINISTRACION de los destinos de impresion.
 *
 * REGLA que fija: *una tienda declara sus impresoras y sus destinos, y el
 * sistema no adivina nada de ellos*.
 *
 *  - el CATALOGO de impresoras es global: el `code` es su clave natural, se
 *    NORMALIZA y es UNICO;
 *  - el DESTINO es por tienda: declara su impresora, su transporte, su
 *    endpoint, su ancho, su codepagina y su soporte de QR;
 *  - el ENDPOINT se VALIDA en el dominio: el CHECK del esquema es la red de
 *    seguridad, no el mensaje. Un endpoint que no se admite falla con un
 *    error que dice QUE endpoint es, QUE transportes admiten endpoint y QUE
 *    formas se aceptan;
 *  - el transporte `browser` NO lleva endpoint: el navegador es el destino;
 *  - los cambios se AUDITAN: quien los hizo, sobre que y desde donde.
 *
 * Y LA COMPROBACION ESTA EN EL SERVIDOR: el permiso protege la ruta, y el
 * boton de la pantalla se oculta con el MISMO slug.
 */

/**
 * El ADMINISTRADOR de la impresion: una persona con los permisos de esta
 * unidad y con su vinculo por la tienda.
 */
function admin_de_impresion(int $storeId): User
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
 * El CATALOGO de impresoras se da de alta, y el codigo se NORMALIZA.
 *
 * REGLA que fija: *el codigo de la impresora es su clave natural: se
 * normaliza (sin espacios alrededor, en mayusculas) y es UNICO*. Un codigo
 * tecleado con espacios es un codigo tecleado mal, y dos impresoras con el
 * mismo codigo son la MISMA impresora con dos nombres.
 */
test('test_el_catalogo_de_impresoras_se_da_de_alta_con_el_codigo_normalizado', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_impresion($escenario['store_id']);

    // 1. El ALTA, con el codigo tecleado como se teclea.
    $respuesta = $this->actingAs($admin)->postJson('/api/impresion/impresoras', [
        'code' => '  imp-01  ',
        'name' => 'Impresora de la tienda',
    ]);

    $respuesta->assertCreated()->assertJsonPath('code', 'IMP-01');

    $impresora = Printer::query()->where('code', 'IMP-01')->first();

    expect($impresora)->not->toBeNull('La impresora no se dio de alta con el codigo normalizado.')
        ->and((string) $impresora->name)->toBe('Impresora de la tienda')
        ->and((bool) $impresora->is_active)->toBeTrue();

    // 2. Un codigo REPETIDO falla con un mensaje util, no con un error de
    //    PostgreSQL: el codigo es la clave natural del catalogo.
    $repetido = $this->actingAs($admin)->postJson('/api/impresion/impresoras', [
        'code' => 'imp-01',
        'name' => 'Otra impresora con el mismo codigo',
    ]);

    $repetido->assertStatus(422);

    $mensaje = (string) $repetido->json('errorMsg');

    expect($mensaje)->toContain('IMP-01')
        ->and($mensaje)->not->toContain('violacion de restriccion')
        ->and($mensaje)->not->toContain('duplicate key');

    // Y no se ha creado una segunda impresora con ese codigo.
    expect(Printer::query()->where('code', 'IMP-01')->count())->toBe(1);
});

/**
 * Un DESTINO se da de alta con su impresora, su transporte, su endpoint, su
 * ancho y su soporte de QR.
 *
 * REGLA que fija: *el destino declara TODO lo que la impresora sabe hacer*.
 * El ancho, la codepagina y el limite del QR son DATO del destino, no una
 * constante del codigo: el sistema no adivina lo que la impresora sabe.
 */
test('test_un_destino_se_da_de_alta_con_su_impresora_su_transporte_y_su_ancho', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_impresion($escenario['store_id']);

    $impresora = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $respuesta = $this->actingAs($admin)->postJson('/api/impresion/destinos', [
        'printer_id' => (int) $impresora->id,
        'name' => 'Caja de la tienda',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.50:9100',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ]);

    $respuesta->assertCreated()->assertJsonPath('name', 'Caja de la tienda');

    $destino = PrintTarget::query()->where('name', 'Caja de la tienda')->first();

    expect($destino)->not->toBeNull('El destino no se dio de alta.')
        ->and((int) $destino->printer_id)->toBe((int) $impresora->id)
        ->and((int) $destino->store_id)->toBe((int) $escenario['store_id'])
        ->and((string) $destino->transport)->toBe('server_socket')
        ->and((string) $destino->endpoint)->toBe('tcp://192.168.1.50:9100')
        ->and((string) $destino->printer_profile)->toBe('default')
        ->and((int) $destino->char_per_line)->toBe(42)
        ->and((string) $destino->charset)->toBe('CP850')
        ->and((int) $destino->max_qr_payload_bytes)->toBe(512)
        ->and((bool) $destino->supports_qr)->toBeTrue()
        ->and((bool) $destino->is_active)->toBeTrue();
});

/**
 * Un endpoint que NO se admite falla con un mensaje que enumera las formas
 * aceptadas.
 *
 * REGLA que fija: *la validacion del endpoint es del DOMINIO, no de
 * PostgreSQL*. El CHECK del esquema es la red de seguridad, no el mensaje: el
 * que falla tiene que leer QUE endpoint es, QUE transportes admiten endpoint
 * y QUE formas se aceptan.
 */
test('test_un_endpoint_no_admitido_falla_con_un_mensaje_que_enumera_las_formas_aceptadas', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_impresion($escenario['store_id']);

    $impresora = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $respuesta = $this->actingAs($admin)->postJson('/api/impresion/destinos', [
        'printer_id' => (int) $impresora->id,
        'name' => 'Destino con USB directo',
        'transport' => 'server_socket',
        'endpoint' => 'usb://localhost/printers/RAW',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ]);

    $respuesta->assertStatus(422);

    $mensaje = (string) $respuesta->json('errorMsg');

    // 1. El mensaje dice QUE endpoint es.
    expect($mensaje)->toContain('usb://localhost/printers/RAW');

    // 2. Y QUE transportes admiten endpoint.
    expect($mensaje)->toContain('lan_agent')
        ->and($mensaje)->toContain('server_socket')
        ->and($mensaje)->toContain('browser');

    // 3. Y QUE formas se aceptan.
    expect($mensaje)->toContain('tcp://servidor:9100')
        ->and($mensaje)->toContain('smb://servidor/impresora')
        ->and($mensaje)->toContain('\\\\servidor\\impresora');

    // 4. Y NO es un error de PostgreSQL: el dominio decide, no la base.
    expect($mensaje)->not->toContain('violacion de restriccion')
        ->and($mensaje)->not->toContain('check constraint')
        ->and($mensaje)->not->toContain('QueryException');

    // Y no se ha creado el destino.
    expect(PrintTarget::query()->where('name', 'Destino con USB directo')->count())->toBe(0);
});

/**
 * El transporte `browser` se acepta SIN endpoint.
 *
 * REGLA que fija: *el navegador ES el destino*. El transporte `browser` no
 * escribe bytes: sirve el comprobante en HTML/PDF desde el mismo modelo de
 * plantilla, asi que no tiene donde escribir y su endpoint es NULO.
 */
test('test_el_transporte_browser_se_acepta_sin_endpoint', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_impresion($escenario['store_id']);

    $impresora = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora del navegador',
    ]);

    $respuesta = $this->actingAs($admin)->postJson('/api/impresion/destinos', [
        'printer_id' => (int) $impresora->id,
        'name' => 'Comprobante en el navegador',
        'transport' => 'browser',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ]);

    $respuesta->assertCreated();

    $destino = PrintTarget::query()->where('name', 'Comprobante en el navegador')->first();

    expect($destino)->not->toBeNull('El destino del navegador no se dio de alta.')
        ->and((string) $destino->transport)->toBe('browser')
        ->and($destino->endpoint)->toBeNull();

    // Y al reves: un destino del navegador CON endpoint se rechaza, y el
    // mensaje dice que el navegador es el destino.
    $conEndpoint = $this->actingAs($admin)->postJson('/api/impresion/destinos', [
        'printer_id' => (int) $impresora->id,
        'name' => 'Navegador con endpoint',
        'transport' => 'browser',
        'endpoint' => 'tcp://192.168.1.50:9100',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ]);

    $conEndpoint->assertStatus(422);

    expect((string) $conEndpoint->json('errorMsg'))->toContain('browser');
});

/**
 * Los cambios de la impresion quedan AUDITADOS.
 *
 * REGLA que fija: *todo cambio de la administracion queda escrito, con QUIEN
 * lo hizo y desde DONDE*. Un destino de impresion que cambia el ancho o el
 * endpoint de una impresora es un cambio de CONFIGURACION, y la
 * configuracion se audita.
 */
test('test_los_cambios_de_impresion_quedan_auditados', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_impresion($escenario['store_id']);

    $impresora = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $this->actingAs($admin)->postJson('/api/impresion/destinos', [
        'printer_id' => (int) $impresora->id,
        'name' => 'Caja de la tienda',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.50:9100',
        'printer_profile' => 'default',
        'char_per_line' => 42,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ])->assertCreated();

    $destino = PrintTarget::query()->where('name', 'Caja de la tienda')->first();

    // 1. El ALTA del destino queda auditada.
    $alta = DB::table('audit_logs')
        ->where('action', 'print_target.created')
        ->orderByDesc('id')
        ->first();

    expect($alta)->not->toBeNull('El alta del destino no quedo auditada.')
        ->and((int) $alta->user_id)->toBe((int) $admin->id)
        ->and((string) $alta->entity_type)->toBe('print_target')
        ->and((int) $alta->entity_id)->toBe((int) $destino->id)
        ->and((string) $alta->ip_address)->not->toBe('');

    $contexto = json_decode((string) $alta->context, true);

    expect($contexto)->toBeArray()
        ->and((string) $contexto['name'])->toBe('Caja de la tienda')
        ->and((int) $contexto['char_per_line'])->toBe(42)
        ->and((string) $contexto['transport'])->toBe('server_socket');

    // 2. Y la EDICION tambien, con el ancho que se cambio.
    $this->actingAs($admin)->putJson('/api/impresion/destinos/'.$destino->id, [
        'printer_id' => (int) $impresora->id,
        'name' => 'Caja de la tienda',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.51:9100',
        'printer_profile' => 'default',
        'char_per_line' => 48,
        'charset' => 'CP850',
        'max_qr_payload_bytes' => 512,
        'supports_qr' => true,
        'is_active' => true,
    ])->assertOk();

    $edicion = DB::table('audit_logs')
        ->where('action', 'print_target.updated')
        ->orderByDesc('id')
        ->first();

    expect($edicion)->not->toBeNull('La edicion del destino no quedo auditada.')
        ->and((int) $edicion->user_id)->toBe((int) $admin->id)
        ->and((int) $edicion->entity_id)->toBe((int) $destino->id);

    $contexto = json_decode((string) $edicion->context, true);

    expect($contexto)->toBeArray()
        ->and((int) $contexto['char_per_line'])->toBe(48)
        ->and((string) $contexto['endpoint'])->toBe('tcp://192.168.1.51:9100');

    // Y el destino tiene el ancho nuevo.
    expect((int) $destino->fresh()->char_per_line)->toBe(48);

    // 3. Y el ALTA de la impresora tambien queda auditada.
    $this->actingAs($admin)->postJson('/api/impresion/impresoras', [
        'code' => 'IMP-AUDITADA',
        'name' => 'Impresora auditada',
    ])->assertCreated();

    $impresoraAuditada = DB::table('audit_logs')
        ->where('action', 'printer.created')
        ->orderByDesc('id')
        ->first();

    expect($impresoraAuditada)->not->toBeNull('El alta de la impresora no quedo auditada.')
        ->and((string) $impresoraAuditada->entity_type)->toBe('printer')
        ->and((int) $impresoraAuditada->user_id)->toBe((int) $admin->id);
});

/**
 * SIN SESION no se llega a la administracion de la impresion.
 *
 * REGLA que fija: *la administracion exige sesion y permiso*. Sin sesion no
 * hay quien decida, y sin permiso la puerta no se abre.
 */
test('test_sin_sesion_no_se_llega_a_la_administracion_de_impresion', function () {
    // 1. La API responde 401: no hay sesion, y no hay quien decida.
    $this->getJson('/api/impresion/destinos')->assertStatus(401);

    $this->postJson('/api/impresion/impresoras', [
        'code' => 'IMP-SIN-SESION',
        'name' => 'Impresora sin sesion',
    ])->assertStatus(401);

    // 2. Y la pantalla redirige al login.
    $this->get('/impresion/destinos')->assertRedirect('/login');
});

/**
 * Sin el PERMISO de la impresion, la administracion no se abre.
 *
 * REGLA que fija: *el permiso es la ley*. Un cajero puede cobrar, pero no
 * cambiar la configuracion de las impresoras de la tienda.
 */
test('test_sin_el_permiso_de_impresion_no_se_abre_la_administracion', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-'.uniqid(), [Permission::SalesCreate->value]),
        $escenario['store_id'],
    );

    $this->actingAs($cajero)->getJson('/api/impresion/destinos')->assertStatus(403);

    $this->actingAs($cajero)->postJson('/api/impresion/impresoras', [
        'code' => 'IMP-SIN-PERMISO',
        'name' => 'Impresora sin permiso',
    ])->assertStatus(403);

    expect(Printer::query()->where('code', 'IMP-SIN-PERMISO')->count())->toBe(0);
});
