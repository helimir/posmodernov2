<?php

use App\Support\Pos\PosContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\VoidRequestFixture;

/**
 * La FECHA DE NEGOCIO es la de la TIENDA, no la del servidor.
 *
 * REGLA que fija: *el dia del negocio es el dia de la tienda*. El reloj del
 * servidor no tiene nada que ver con el del negocio: la tienda de
 * demostracion declara `America/Caracas` (UTC-4), y entre las 00:00 y las
 * 04:00 UTC el servidor ya esta en el dia siguiente.
 *
 * Y POR QUE IMPORTA: *una venta y su caja tienen que estar en el MISMO
 * dia*. La guarda del dia cerrado compara el `sale_date` de la venta con el
 * `business_date` de la caja: si son dos dias distintos, la guarda no
 * dispara, y el test falla sin razon o pasa POR EL MOTIVO EQUIVOCADO.
 *
 * Y EL RELOJ SE CONGELA, PORQUE SI NO EL TEST DEPENDE DE LA HORA: el
 * instante congelado es del dia 25 a la 01:30 UTC, que en Caracas todavia
 * es el dia 24. Y la zona del SERVIDOR se cambia a proposito (Kiritimati,
 * UTC+14): el escenario tiene que funcionar con cualquier zona del
 * servidor, y no con la que le sale por casualidad.
 */
test('test_la_fecha_de_negocio_es_la_de_la_tienda_y_no_la_del_servidor', function () {
    // La ZONA DEL SERVIDOR se cambia a proposito: el reloj del negocio es el
    // de la tienda, y el escenario no puede depender del del servidor.
    $zonaOriginal = date_default_timezone_get();

    config(['app.timezone' => 'Pacific/Kiritimati']);
    date_default_timezone_set('Pacific/Kiritimati');

    // Y EL RELOJ SE CONGELA en un instante en el que el dia del servidor y el
    // dia de la tienda NO son el mismo: el 25 a la 01:30 UTC, que en Caracas
    // (UTC-4) todavia es el 24.
    $this->travelTo(Carbon::parse('2026-11-25 01:30:00', 'UTC'));

    try {
        $contexto = VoidRequestFixture::escenario();

        $venta = VoidRequestFixture::venta($contexto['store_id'], 'FECHA-0001');

        // 1. La fecha de negocio de la TIENDA, segun el DOMINIO: una sola
        //    resolucion, y la misma que usa la caja.
        $fechaDeLaTienda = (new PosContext(DB::connection()))
            ->businessDateOf($contexto['store_id'])
            ->format('Y-m-d');

        // 2. La VENTA la lleva: la de la tienda, no la del servidor.
        expect($venta->sale_date->format('Y-m-d'))->toBe($fechaDeLaTienda);

        // 3. Y la CAJA DEL DIA se encuentra con ESA MISMA fecha: la venta y
        //    su caja estan en el MISMO dia, que es lo que hace que la guarda
        //    del dia cerrado compare dos dias que son el mismo.
        $caja = VoidRequestFixture::cajaDelDia($contexto['store_id']);

        expect((string) $caja->business_date)->toBe($fechaDeLaTienda)
            ->and($venta->sale_date->format('Y-m-d'))->toBe((string) $caja->business_date);

        // 4. Y la fecha del SERVIDOR es OTRA: con el servidor en Kiritimati
        //    son las 15:30 del dia 25, y la tienda sigue en el 24. Si el
        //    escenario usara la fecha del servidor, esto seria igual.
        expect(now()->format('Y-m-d'))->not->toBe($fechaDeLaTienda)
            ->and($venta->sale_date->format('Y-m-d'))->not->toBe(now()->format('Y-m-d'));
    } finally {
        date_default_timezone_set($zonaOriginal);
    }
});
