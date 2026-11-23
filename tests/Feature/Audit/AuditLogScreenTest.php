<?php

use App\Domain\Auth\Enums\Permission;
use App\Support\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * La PANTALLA de la auditoria.
 *
 * REGLA que fija esta unidad: *la auditoria se consulta, y solo con el
 * permiso de leerla*.
 *
 *  - quien tiene `audit.read` ve las entradas, y puede filtrarlas;
 *  - quien no lo tiene, no entra: la pantalla no es un dato publico;
 *  - una entrada **sin usuario** (una accion del sistema) **no desaparece**
 *    de la vista: un `join` la borraria, y eso seria peor que no
 *    auditarla.
 */
describe('la pantalla de la auditoria', function () {
    it('el superusuario ve la pantalla con las entradas', function () {
        PermissionFixture::catalogo();
        $escenario = PosFixture::escenario();
        $usuario = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

        (new AuditWriter(DB::connection()))->record(
            'sale.created',
            'sale',
            42,
            ['total' => '58.0000'],
            (int) $usuario->id,
            (int) $escenario['store_id'],
            '127.0.0.1',
        );

        $respuesta = $this->actingAs($usuario)->get('/contabilidad/auditoria');

        $respuesta->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounting/audit')
                ->where('logs.0.action', 'sale.created')
                ->where('logs.0.entity_type', 'sale')
                ->where('logs.0.entity_id', 42)
                ->where('logs.0.user_name', $usuario->name)
                ->where('logs.0.context.total', '58.0000')
                ->where('pagination.total', 1)
            );
    });

    it('quien no tiene el permiso no entra', function () {
        PermissionFixture::catalogo();
        $escenario = PosFixture::escenario();

        $rol = PermissionFixture::rol('sin_auditoria', [Permission::DashboardRead->value]);

        $usuario = PermissionFixture::usuario($rol, $escenario['store_id']);

        $this->actingAs($usuario)
            ->get('/contabilidad/auditoria')
            ->assertForbidden();
    });

    it('filtra por accion', function () {
        PermissionFixture::catalogo();
        $escenario = PosFixture::escenario();
        $usuario = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

        $escritor = new AuditWriter(DB::connection());

        $escritor->record('sale.created', 'sale', 1, [], (int) $usuario->id, (int) $escenario['store_id']);
        $escritor->record('cash_register.opened', 'cash_register', 1, [], (int) $usuario->id, (int) $escenario['store_id']);

        $respuesta = $this->actingAs($usuario)->get('/contabilidad/auditoria?action=cash_register.opened');

        $respuesta->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounting/audit')
                ->where('filters.action', 'cash_register.opened')
                ->where('pagination.total', 1)
                ->has('logs', 1)
                ->where('logs.0.action', 'cash_register.opened')
            );
    });

    it('una entrada sin usuario no desaparece de la vista', function () {
        PermissionFixture::catalogo();
        $escenario = PosFixture::escenario();
        $usuario = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

        // Una anotacion SIN actor: la hizo el sistema, no una persona.
        (new AuditWriter(DB::connection()))->record(
            'system.night_close',
            'system',
            null,
            ['note' => 'cierre nocturno'],
            null,
            (int) $escenario['store_id'],
        );

        $respuesta = $this->actingAs($usuario)->get('/contabilidad/auditoria');

        $respuesta->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('accounting/audit')
                ->has('logs', 1)
                ->where('logs.0.action', 'system.night_close')
                ->where('logs.0.user_name', null)
            );
    });
});
