<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\VoidRequestFixture;

/**
 * El ESQUEMA de las solicitudes de anulacion: UNA FILA por solicitud, y el
 * esquema prohibe lo imposible.
 *
 * Reglas que fija:
 *
 *  - la peticion es UNA FILA y la VENTA no la guarda: una venta puede tener
 *    VARIAS peticiones (una rechazada y otra nueva), y con tres columnas en
 *    la cabecera solo se guardaba la ultima. Una sola fuente de verdad;
 *  - el ESQUEMA prohibe los estados IMPOSIBLES: una solicitud rechazada sin
 *    motivo no entra, ni una pendiente con un rechazo, ni un estado fuera
 *    del conjunto cerrado;
 *  - UNA VENTA, UNA SOLICITUD PENDIENTE: lo dice el indice unico parcial,
 *    y por eso dos peticiones a la vez no pueden colarse.
 */

/**
 * La INSERCION que el esquema tiene que PROHIBIR.
 *
 * Y VA DENTRO DE UNA TRANSACCION ANIDADA: en PostgreSQL una sentencia que
 * falla ABORTA la transaccion entera, y sin el punto de guardado todo lo
 * que viene despues fallaria tambien (con el error 25P02, que no es el del
 * esquema). El `ROLLBACK TO SAVEPOINT` deja viva la transaccion de prueba.
 */
function inserta_una_solicitud_de_anulacion(array $fila): void
{
    DB::transaction(function () use ($fila): void {
        DB::table('sale_void_requests')->insert($fila);
    });
}

test('test_la_peticion_de_anulacion_es_una_fila_y_la_venta_no_la_guarda', function () {
    expect(Schema::hasTable('sale_void_requests'))->toBeTrue();

    $columnas = Schema::getColumnListing('sale_void_requests');

    expect($columnas)->toContain(
        'sale_id',
        'store_id',
        'status',
        'reason',
        'requested_by',
        'requested_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    );

    // Y la VENTA ya no lleva la peticion: la peticion es una fila, y dejarla
    // en la cabecera seria una segunda fuente de verdad.
    expect(Schema::getColumnListing('sales'))
        ->not->toContain('void_requested_at')
        ->not->toContain('void_request_reason')
        ->not->toContain('void_requested_by');
});

/**
 * El ESQUEMA prohibe los estados IMPOSIBLES de una solicitud.
 *
 * REGLA que fija: *lo imposible no entra en la base*. Una solicitud
 * rechazada sin motivo no le dice nada a quien la pidio; una pendiente con un
 * rechazo a la vez son dos verdades; y un estado fuera del conjunto cerrado
 * es un estado que el dominio no tiene.
 */
test('test_el_esquema_de_la_solicitud_de_anulacion_prohibe_los_estados_imposibles', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'ESQ-0001');

    $base = [
        'sale_id' => $venta->id,
        'store_id' => $contexto['store_id'],
        'requested_by' => (int) $contexto['cajero']->id,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // 1. Una solicitud RECHAZADA sin motivo no entra.
    expect(fn () => inserta_una_solicitud_de_anulacion($base + [
        'status' => 'rejected',
        'reason' => 'Motivo de la peticion',
    ]))->toThrow(QueryException::class);

    // 2. Y una PENDIENTE con un rechazo tampoco: son dos verdades.
    expect(fn () => inserta_una_solicitud_de_anulacion($base + [
        'status' => 'pending',
        'reason' => 'Motivo de la peticion',
        'rejected_at' => now(),
        'rejection_reason' => 'Motivo del rechazo',
    ]))->toThrow(QueryException::class);

    // 3. Y un estado fuera del conjunto cerrado, tampoco: el dominio tiene
    //    tres, y el cuarto ("hecha") no es un estado de la solicitud.
    expect(fn () => inserta_una_solicitud_de_anulacion($base + [
        'status' => 'done',
        'reason' => 'Motivo de la peticion',
    ]))->toThrow(QueryException::class);

    // 4. Y una solicitud SIN MOTIVO tampoco: una peticion sin motivo es una
    //    peticion que nadie sabe por que se hizo.
    expect(fn () => inserta_una_solicitud_de_anulacion($base + [
        'status' => 'pending',
        'reason' => '   ',
    ]))->toThrow(QueryException::class);

    // Y la peticion VALIDA entra: el esquema no prohibe lo posible.
    inserta_una_solicitud_de_anulacion($base + [
        'status' => 'pending',
        'reason' => 'El cliente devolvio la mercaderia',
    ]);

    expect(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(1);
});

/**
 * Una venta NO tiene dos solicitudes de anulacion PENDIENTES.
 *
 * REGLA que fija: *una venta tiene UNA solicitud pendiente*. La segunda no
 * pisa la primera: el motivo del primero es el que explica la anulacion, y
 * pisarlo seria dejar la anulacion sin explicacion. Lo dice el indice unico
 * parcial, que solo mira las pendientes.
 */
test('test_una_venta_no_tiene_dos_solicitudes_de_anulacion_pendientes', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'ESQ-0002');

    $fila = [
        'sale_id' => $venta->id,
        'store_id' => $contexto['store_id'],
        'status' => 'pending',
        'reason' => 'El cliente devolvio la mercaderia',
        'requested_by' => (int) $contexto['cajero']->id,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // La primera entra.
    inserta_una_solicitud_de_anulacion($fila);

    // Y la segunda NO: una venta tiene una solicitud pendiente.
    expect(fn () => inserta_una_solicitud_de_anulacion($fila))
        ->toThrow(QueryException::class);

    expect(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(1);
});
