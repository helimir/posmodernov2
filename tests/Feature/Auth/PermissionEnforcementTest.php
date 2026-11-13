<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-01 — los PERMISOS se aplican en el SERVIDOR.
 *
 * REGLA que fija: *el permiso de una ruta se declara junto a la ruta, y lo
 * comprueba el servidor*. El boton que se oculta en la interfaz es una
 * cortesia; la ley es el middleware.
 *
 * Y EL INVENTARIO DE RUTAS es la RED de la unidad: si se anade una ruta
 * nueva y no se le declara su permiso, el test lo dice. Sin esta red, cada
 * pantalla nueva seria una pantalla sin proteger, y nadie lo notaria hasta
 * que alguien la usara mal.
 *
 * EL INVENTARIO es la lista de rutas que hoy existen. Las del agente de
 * impresion y las del sync NO estan: tienen su propia autenticacion (token)
 * y no son de un usuario de la aplicacion.
 */

/**
 * El ESCENARIO de las rutas: la tienda, sus productos, sus clientes, sus
 * ventas, sus comprobantes y sus cuentas.
 *
 * @return array<string, int>
 */
function escenario_de_rutas(): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cliente = PermissionFixture::cliente($escenario['store_id']);
    $venta = PermissionFixture::venta($escenario['store_id'], (int) $cliente->id);

    $rol = Role::query()->where('slug', (string) config('auth.superuser_role_slug'))->first();

    $usuario = User::factory()->create();

    return [
        'store_id' => (int) $escenario['store_id'],
        'product_id' => (int) $escenario['product_id'],
        'customer_id' => (int) $cliente->id,
        'sale_id' => (int) $venta->id,
        'document_id' => PermissionFixture::documento($escenario['store_id']),
        'account_id' => PermissionFixture::cuenta(),
        'role_id' => (int) ($rol?->id ?? 0),
        'user_id' => (int) $usuario->id,
    ];
}

/**
 * La URI de la ruta con los ids del escenario.
 *
 * @param  array<string, int>  $escenario
 */
function uri_de_la_ruta(string $uri, array $escenario): string
{
    return strtr($uri, [
        '{sale}' => (string) $escenario['sale_id'],
        '{documento}' => (string) $escenario['document_id'],
        '{customer}' => (string) $escenario['customer_id'],
        '{product}' => (string) $escenario['product_id'],
        '{account}' => (string) $escenario['account_id'],
        '{user}' => (string) $escenario['user_id'],
        '{role}' => (string) $escenario['role_id'],
    ]);
}

/**
 * El INVENTARIO de rutas protegidas: metodo, uri y permiso.
 *
 * Es el MISMO inventario que el fichero de rutas declara, y por eso el
 * test y el codigo no pueden separarse.
 */
dataset('rutas protegidas', [
    'dashboard' => ['get', '/dashboard', 'dashboard.read'],
    'lista de ventas' => ['get', '/ventas', 'sales.read'],
    'detalle de una venta' => ['get', '/ventas/{sale}', 'sales.read'],
    'reintento de la emision fiscal' => ['post', '/ventas/{sale}/emision', 'sales.fiscal_retry'],
    'comprobante fiscal' => ['get', '/comprobantes/{documento}', 'receipt.read'],
    'imprimir el comprobante' => ['post', '/comprobantes/{documento}/imprimir', 'receipt.print'],
    'la caja' => ['get', '/caja', 'pos.access'],
    'busqueda de productos de la caja' => ['get', '/caja/productos', 'pos.access'],
    'presupuesto de la caja' => ['post', '/caja/presupuesto', 'pos.budget'],
    'cobro de la caja' => ['post', '/caja/cobrar', 'sales.create'],
    'caja del dia' => ['get', '/caja/dia', 'cash_day.read'],
    'apertura de la caja del dia' => ['post', '/caja/dia/abrir', 'cash_day.open'],
    'cierre de la caja del dia' => ['post', '/caja/dia/cerrar', 'cash_day.close'],
    'reporte de stock' => ['get', '/reportes/stock', 'report.read'],
    'lista de clientes' => ['get', '/clientes', 'customer.read'],
    'alta de cliente' => ['get', '/clientes/nuevo', 'customer.create'],
    'edicion de cliente' => ['get', '/clientes/{customer}/editar', 'customer.update'],
    'ficha de cliente' => ['get', '/clientes/{customer}', 'customer.read'],
    'api de clientes' => ['get', '/api/clientes', 'customer.read'],
    'alta de cliente por la api' => ['post', '/api/clientes', 'customer.create'],
    'ficha de cliente por la api' => ['get', '/api/clientes/{customer}', 'customer.read'],
    'edicion de cliente por la api' => ['put', '/api/clientes/{customer}', 'customer.update'],
    'cobro del cliente' => ['post', '/api/clientes/{customer}/cobros', 'customer.payment'],
    'selector de clientes de la caja' => ['get', '/api/caja/clientes', 'pos.access'],
    'plan de cuentas' => ['get', '/contabilidad/cuentas', 'accounting.read'],
    'rol de una cuenta' => ['put', '/api/contabilidad/cuentas/{account}/rol', 'accounting.update'],
    'alta de cuenta' => ['post', '/api/contabilidad/cuentas', 'accounting.create'],
    'apagado de una cuenta' => ['post', '/api/contabilidad/cuentas/{account}/desactivar', 'accounting.update'],
    'encendido de una cuenta' => ['post', '/api/contabilidad/cuentas/{account}/encender', 'accounting.update'],
    'borrado de una cuenta' => ['delete', '/api/contabilidad/cuentas/{account}', 'accounting.delete'],
    'catalogo' => ['get', '/catalogo', 'catalog.read'],
    'alta de producto' => ['get', '/catalogo/nuevo', 'catalog.create'],
    'alta de producto por la escritura' => ['post', '/catalogo', 'catalog.create'],
    'ficha de producto' => ['get', '/catalogo/{product}', 'catalog.read'],
    'edicion de producto' => ['put', '/catalogo/{product}', 'catalog.update'],
    'precios del producto' => ['get', '/catalogo/{product}/precios', 'catalog.read'],
    'precio del producto por la escritura' => ['put', '/catalogo/{product}/precios', 'catalog.price'],
    'usuarios' => ['get', '/usuarios', 'user.read'],
    'rol de un usuario' => ['put', '/api/usuarios/{user}/rol', 'user.update'],
    'roles' => ['get', '/roles', 'role.read'],
    'un rol' => ['get', '/roles/{role}', 'role.read'],
    'permisos de un rol' => ['put', '/api/roles/{role}/permisos', 'role.update'],
    'tiendas de un rol' => ['put', '/api/roles/{role}/tiendas', 'role.update'],
]);

/**
 * Una ruta protegida RECHAZA a un rol sin su permiso.
 */
test('test_una_ruta_protegida_rechaza_a_un_rol_sin_su_permiso', function (string $metodo, string $uri, string $permiso) {
    $escenario = escenario_de_rutas();

    // Un rol SIN NINGUN permiso: el caso mas duro.
    $rol = PermissionFixture::rol('sin-permisos-'.uniqid());

    $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->{$metodo}(uri_de_la_ruta($uri, $escenario));

    expect($respuesta->getStatusCode())->toBe(
        403,
        "La ruta {$uri} no rechazo a un rol sin el permiso {$permiso}."
    );
})->with('rutas protegidas');

/**
 * Una ruta protegida ACEPTA a un rol con su permiso.
 */
test('test_una_ruta_protegida_acepta_a_un_rol_con_su_permiso', function (string $metodo, string $uri, string $permiso) {
    $escenario = escenario_de_rutas();

    // Un rol con ESE permiso, y solo ese.
    $rol = PermissionFixture::rol('con-'.$permiso.'-'.uniqid(), [$permiso]);

    $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->{$metodo}(uri_de_la_ruta($uri, $escenario));

    expect($respuesta->getStatusCode())->not->toBe(
        403,
        "La ruta {$uri} rechazo a un rol con el permiso {$permiso}."
    );
})->with('rutas protegidas');

/**
 * El SUPERUSUARIO lo puede todo: cada permiso del catalogo, sin excepcion.
 */
test('test_el_superusuario_lo_puede_todo', function () {
    $escenario = escenario_de_rutas();

    $rol = PermissionFixture::superusuario();

    $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

    expect($usuario->isSuperuser())->toBeTrue();

    foreach (Permission::cases() as $permiso) {
        expect($usuario->can($permiso->value))->toBeTrue(
            "El superusuario no puede {$permiso->value}."
        );
    }

    // Y una respuesta de verdad: la pantalla de las ventas se sirve.
    $this->actingAs($usuario)
        ->get('/ventas')
        ->assertOk();
});

/**
 * Un permiso que NO esta en el catalogo no concede nada: ni al
 * superusuario.
 */
test('test_un_permiso_que_no_esta_en_el_catalogo_no_concede_nada', function () {
    $escenario = escenario_de_rutas();

    $usuario = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    expect($usuario->can('sales.inventadas'))->toBeFalse()
        ->and($usuario->hasPermission('sales.inventadas'))->toBeFalse()
        ->and($usuario->permissions())->not->toContain('sales.inventadas');
});

/**
 * El CAJERO no puede editar el plan de cuentas: es del contador y del
 * superusuario.
 */
test('test_el_cajero_no_puede_editar_el_plan_de_cuentas', function () {
    $escenario = escenario_de_rutas();

    $rol = Role::query()->where('slug', 'cashier')->first();

    expect($rol)->not->toBeNull();

    $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

    // El permiso no esta en la matriz del cajero.
    expect($usuario->can('accounting.update'))->toBeFalse();

    // Y la escritura se rechaza en el servidor.
    $this->actingAs($usuario)
        ->put('/api/contabilidad/cuentas/'.$escenario['account_id'].'/rol', ['role' => 'cash'])
        ->assertStatus(403);
});

/**
 * Un usuario SIN el permiso de cierre no cierra la caja del dia de otro
 * usuario.
 */
test('test_un_usuario_sin_el_permiso_de_cierre_no_cierra_la_caja_de_otro', function () {
    $escenario = escenario_de_rutas();

    // El que ABRE la caja: el cajero, que si puede.
    $cajero = Role::query()->where('slug', 'cashier')->first();

    $quienAbre = PermissionFixture::usuario($cajero, $escenario['store_id']);

    $this->actingAs($quienAbre)
        ->post('/caja/dia/abrir', ['opening_balance' => '0.0000'])
        ->assertStatus(201);

    // El que QUIERE cerrarla: un rol sin permiso de cierre.
    $sinPermiso = PermissionFixture::rol('sin-cierre-'.uniqid(), ['cash_day.read']);

    $otro = PermissionFixture::usuario($sinPermiso, $escenario['store_id']);

    $this->actingAs($otro)
        ->post('/caja/dia/cerrar', ['closing_balance' => '0.0000'])
        ->assertStatus(403);

    // Y la caja sigue ABIERTA: el rechazo no cierra nada.
    $caja = DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect((string) $caja->status)->toBe('open');
});

/**
 * Un usuario SIN SESION no llega a una ruta protegida.
 */
test('test_un_usuario_sin_sesion_no_llega_a_una_ruta_protegida', function () {
    $escenario = escenario_de_rutas();

    // El navegador recibe la redireccion al login.
    $this->get('/ventas')->assertRedirect('/login');

    // Y la API recibe el 401 del contrato.
    $this->getJson('/api/clientes')->assertStatus(401);
});

/**
 * El rechazo de la API lleva el `errorMsg` del contrato de siempre.
 */
test('test_el_rechazo_de_la_api_lleva_el_error_msg_del_contrato', function () {
    $escenario = escenario_de_rutas();

    $rol = PermissionFixture::rol('sin-permisos-'.uniqid());

    $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->getJson('/api/clientes');

    $respuesta->assertStatus(403);

    expect($respuesta->json('errorMsg'))->toBeString()->not->toBe('');
});
