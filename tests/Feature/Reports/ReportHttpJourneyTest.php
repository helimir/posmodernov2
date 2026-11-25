<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\ReportFixture;

/**
 * El MOTOR DE REPORTES por HTTP: las pantallas y la exportacion.
 *
 * Reglas que fija:
 *
 *  - las pantallas de reportes son de SOLO LECTURA y responden 200 con su
 *    contrato: las filas, los totales, el periodo, la tienda y la moneda;
 *  - el PERIODO viaja en la peticion (`from` y `to`), y el que no lo pide ve
 *    el mes de la tienda;
 *  - el PERMISO protege la pantalla: quien no tiene `report.read` no la ve;
 *  - la EXPORTACION necesita `report.export`, que es un permiso DISTINTO:
 *    sacar los datos del sistema es otra cosa que mirarlos;
 *  - el CSV exportado NEUTRALIZA la inyeccion de formula: un nombre de
 *    producto que empieza por `=` sale con un apostrofo delante;
 *  - un reporte NO puede enseñar datos de una tienda fuera del ambito del
 *    ROL.
 */

/**
 * El ROL del contador del reporte, con los permisos que se le pasan.
 *
 * @param  list<Permission>  $permisos
 */
function reportes_rol(array $permisos): Role
{
    PermissionFixture::catalogo();

    return PermissionFixture::rol(
        'contador-reportes-'.DB::table('roles')->count(),
        array_map(static fn (Permission $permiso): string => $permiso->value, $permisos)
    );
}

/**
 * Las PANTALLAS de reportes responden 200 con su contrato.
 */
test('test_las_pantallas_de_reportes_responden_con_su_contrato', function () {
    $storeId = ReportFixture::store();

    $rol = reportes_rol([Permission::ReportRead]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'grand_total' => '58.0000',
    ]);

    $this->actingAs($usuario)
        ->get('/reportes/ventas?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/sales')
            ->where('period.from', '2026-01-01')
            ->where('period.to', '2026-01-31')
            ->where('store.id', $storeId)
            ->has('currency')
            ->has('rows')
            ->has('totals')
            ->where('totals.sales_count', 1)
            ->where('totals.grand_total', '58.0000')
            ->etc());

    // Y las demas pantallas de la unidad.
    foreach ([
        '/reportes/ventas/productos' => 'reports/sales-products',
        '/reportes/ventas/cajeros' => 'reports/sales-cashiers',
        '/reportes/iva' => 'reports/vat',
        '/reportes/resultados' => 'reports/profit-and-loss',
        '/reportes/libro-caja' => 'reports/cash-book',
        '/reportes/clientes' => 'reports/customer-aging',
        '/reportes/inventario' => 'reports/valued-inventory',
        '/reportes/arqueo' => 'reports/cash-count',
        '/reportes/z' => 'reports/period-z',
    ] as $ruta => $componente) {
        $this->actingAs($usuario)
            ->get($ruta)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component($componente)
                ->has('rows')
                ->has('totals')
                ->etc());
    }
});

/**
 * Sin `report.read` no hay reporte: el permiso se comprueba en el servidor.
 */
test('test_un_usuario_sin_permiso_de_reportes_no_ve_los_reportes', function () {
    $storeId = ReportFixture::store();

    $rol = reportes_rol([]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    $this->actingAs($usuario)
        ->get('/reportes/ventas')
        ->assertForbidden();
});

/**
 * La EXPORTACION necesita su propio permiso: sacar los datos es otra cosa
 * que mirarlos.
 */
test('test_la_exportacion_necesita_el_permiso_de_exportar', function () {
    $storeId = ReportFixture::store();

    $rol = reportes_rol([Permission::ReportRead]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    $this->actingAs($usuario)
        ->get('/reportes/exportar/ventas')
        ->assertForbidden();

    // Y con el permiso, el fichero sale.
    $rolConExport = reportes_rol([Permission::ReportRead, Permission::ReportExport]);

    $otro = PermissionFixture::usuario($rolConExport, $storeId);

    $this->actingAs($otro)
        ->get('/reportes/exportar/ventas')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

/**
 * El CSV exportado NEUTRALIZA la inyeccion de formula.
 */
test('test_el_csv_exportado_neutraliza_la_inyeccion_de_formula', function () {
    $storeId = ReportFixture::store();

    // Un producto con un nombre que Excel ejecutaria.
    $productoId = ReportFixture::producto('REP-INYECCION', "=cmd|'/c calc'!A1");

    $rol = reportes_rol([Permission::ReportRead, Permission::ReportExport]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'product_id' => $productoId,
    ]);

    $respuesta = $this->actingAs($usuario)
        ->get('/reportes/exportar/ventas-productos?from=2026-01-01&to=2026-01-31');

    $respuesta->assertOk();

    $csv = $respuesta->streamedContent();

    expect($csv)->toContain("'=cmd|'/c calc'!A1")
        ->and($csv)->not->toContain(',=cmd');
});

/**
 * Un reporte NO puede enseñar los datos de una tienda fuera del ambito del
 * ROL.
 */
test('test_un_reporte_no_enseña_los_datos_de_una_tienda_fuera_del_ambito', function () {
    $tiendaDelRol = ReportFixture::store('Tienda del ambito');
    $tiendaFuera = ReportFixture::store('Tienda fuera del ambito');

    $rol = reportes_rol([Permission::ReportRead]);

    $usuario = PermissionFixture::usuario($rol, $tiendaDelRol);

    PermissionFixture::ambito($rol, [$tiendaDelRol]);

    ReportFixture::sale([
        'store_id' => $tiendaDelRol,
        'date' => '2026-01-10',
        'grand_total' => '58.0000',
    ]);

    ReportFixture::sale([
        'store_id' => $tiendaFuera,
        'date' => '2026-01-10',
        'grand_total' => '99.0000',
    ]);

    $this->actingAs($usuario)
        ->get('/reportes/ventas?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('totals.sales_count', 1)
            ->where('totals.grand_total', '58.0000')
            ->etc());

    // Y la contabilidad, que aplica el ambito por su cuenta, tampoco.
    $this->actingAs($usuario)
        ->get('/reportes/resultados?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows', [])
            ->etc());
});

/**
 * Un reporte de una fecha que no existe es un error con su motivo.
 */
test('test_un_periodo_invalido_es_un_error_con_su_motivo', function () {
    $storeId = ReportFixture::store();

    $rol = reportes_rol([Permission::ReportRead]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    $this->actingAs($usuario)
        ->get('/reportes/ventas?from=2026-01-31&to=2026-01-01')
        ->assertStatus(422);
});

/**
 * La EXPORTACION es de un conjunto CERRADO de reportes: un nombre que no
 * existe no es un reporte.
 */
test('test_la_exportacion_de_un_reporte_que_no_existe_es_un_404', function () {
    $storeId = ReportFixture::store();

    $rol = reportes_rol([Permission::ReportRead, Permission::ReportExport]);

    $usuario = PermissionFixture::usuario($rol, $storeId);

    $this->actingAs($usuario)
        ->get('/reportes/exportar/inventario-2026')
        ->assertNotFound();
});
