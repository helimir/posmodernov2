<?php

use App\Support\Audit\AuditWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * La AUDITORIA de negocio.
 *
 * REGLA que fija esta unidad: *todo cambio importante queda escrito, con
 * QUIEN lo hizo y desde DONDE; y lo que se anoto no se puede reescribir*.
 *
 *  - la auditoria **NO se traga el cambio**: si la escritura falla, el
 *    cambio ya esta hecho y la peticion sigue;
 *  - `audit_logs` es **append-only**: una fila anotada no se edita ni se
 *    borra, porque una prueba que se puede rehacer no prueba nada;
 *  - las escrituras que **mas se disputan** quedan anotadas: la venta, la
 *    apertura, el cierre y el cobro a un cliente.
 */
describe('la auditoria de negocio', function () {
    it('anota el actor, la tienda y la ip de la peticion', function () {
        $escenario = PosFixture::escenario();
        $usuario = PosFixture::usuario($escenario['store_id']);

        $escritor = new AuditWriter(DB::connection());

        $escrito = $escritor->recordFor(
            request(),
            'prueba.escrita',
            'prueba',
            7,
            ['detalle' => 'algo'],
            (int) $escenario['store_id']
        );

        expect($escrito)->toBeTrue();

        $fila = DB::table('audit_logs')->where('action', 'prueba.escrita')->first();

        expect($fila)->not->toBeNull()
            ->and($fila->entity_type)->toBe('prueba')
            ->and((int) $fila->entity_id)->toBe(7)
            ->and((int) $fila->store_id)->toBe((int) $escenario['store_id'])
            ->and($fila->context)->toContain('detalle');
    });

    it('no se traga el cambio cuando la escritura falla', function () {
        // Una conexion que no llega a ninguna base: la escritura falla y el
        // escritor tiene que DEVOLVERLO, no lanzar. El cambio ya esta
        // hecho, y deshacerlo por no haberlo anotado seria peor.
        //
        // El `purge` es necesario: cambiar el nombre de la base no
        // reconecta un PDO que ya esta abierto.
        config(['database.connections.pgsql_b.database' => 'base_que_no_existe']);
        DB::purge('pgsql_b');

        $escritor = new AuditWriter(DB::connection('pgsql_b'));

        $escrito = $escritor->record('prueba.que.falla', 'prueba', 1);

        expect($escrito)->toBeFalse();
    });

    it('es append-only: lo anotado no se edita ni se borra', function () {
        // La escritura y las comprobaciones van por la **segunda conexion**
        // (`pgsql_b`), porque el `RAISE EXCEPTION` del trigger ABORTA la
        // transaccion: si el fallo ocurriera en la conexion del test, el
        // test se quedaria sin conexion ("current transaction is aborted")
        // y no podria comprobar que la fila sigue ahi.
        $otra = DB::connection('pgsql_b');

        $id = (int) $otra->table('audit_logs')->insertGetId([
            'user_id' => null,
            'store_id' => null,
            'action' => 'prueba.append',
            'entity_type' => 'prueba',
            'entity_id' => 3,
            'context' => json_encode(['a' => 'b']),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($id)->toBeGreaterThan(0);

        // Una EDICION falla.
        expect(fn () => $otra->table('audit_logs')->where('id', $id)->update(['action' => 'otro']))
            ->toThrow(QueryException::class);

        // Y un BORRADO tambien.
        expect(fn () => $otra->table('audit_logs')->where('id', $id)->delete())
            ->toThrow(QueryException::class);

        // La fila sigue ahi, intacta.
        expect($otra->table('audit_logs')->where('id', $id)->value('action'))->toBe('prueba.append');
    });

    it('la venta queda anotada', function () {
        $escenario = PosFixture::escenario();
        $usuario = PosFixture::usuario($escenario['store_id']);

        $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ]);

        $respuesta->assertCreated();

        $anotacion = DB::table('audit_logs')->where('action', 'sale.created')->first();

        expect($anotacion)->not->toBeNull()
            ->and($anotacion->entity_type)->toBe('sale')
            ->and((int) $anotacion->entity_id)->toBe((int) $respuesta->json('sale_id'))
            ->and((int) $anotacion->store_id)->toBe((int) $escenario['store_id'])
            ->and((int) $anotacion->user_id)->toBe((int) $usuario->id);
    });

    it('la apertura y el cierre de la caja quedan anotados', function () {
        $escenario = PosFixture::escenario();
        $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

        $this->actingAs($usuario)
            ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
            ->assertCreated();

        $apertura = DB::table('audit_logs')->where('action', 'cash_register.opened')->first();

        expect($apertura)->not->toBeNull()
            ->and($apertura->context)->toContain('100.0000');

        $this->actingAs($usuario)
            ->postJson('/caja/dia/cerrar', [
                'closing_balance' => '100.0000',
                'reason' => 'La caja cuadrada, sin descuadre.',
            ])
            ->assertCreated();

        $cierre = DB::table('audit_logs')->where('action', 'cash_register.closed')->first();

        expect($cierre)->not->toBeNull()
            ->and($cierre->context)->toContain('La caja cuadrada');
    });
});
