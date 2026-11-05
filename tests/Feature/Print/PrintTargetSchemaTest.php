<?php

use App\Models\Printer;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.8.1 — los destinos de impresión admitidos.
 *
 * Regla que fija: el USB directo se descarta por requisito de Zadig.
 *
 * Un destino de impresión es SIEMPRE una impresora de red por TCP 9100 o una
 * impresora compartida de Windows. El acceso USB directo en Windows exige
 * instalar un driver con Zadig, que no es viable en un despliegue desatendido,
 * así que no es un destino: no existe como transporte y tampoco puede
 * colarse por el endpoint.
 *
 * El transporte `browser` es el caso contrario: no tiene endpoint, porque
 * renderiza HTML/PDF desde el MISMO modelo de plantilla en vez de escribir
 * bytes en una impresora.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): en
 * PostgreSQL un error dentro de una transaccion la ABORTA entera, y aqui hace
 * falta que un destino rechazado no se lleve por delante el resto del test.
 */
uses(WithoutWrappingTransaction::class);

/**
 * Un codigo unico: `printers.code` y `print_targets (store_id, name)` son
 * UNIQUE y los tests de este fichero NO van con transaccion envolvente, asi
 * que sus filas sobreviven al test.
 */
function codigo_de_impresora(string $prefijo = 'IMP'): string
{
    return $prefijo.'-'.strtoupper(substr(md5(uniqid('', true)), 0, 10));
}

test('test_un_destino_usb_directo_se_rechaza_y_exige_tcp_9100_o_impresora_compartida', function () {
    $printer = Printer::create([
        'code' => codigo_de_impresora(),
        'name' => 'Impresora de la tienda',
    ]);

    $store = Store::create(['name' => 'Tienda de impresion '.codigo_de_impresora('TIENDA')]);

    // 1. El USB directo NO es un transporte: el enum del esquema lo rechaza.
    expect(fn () => DB::table('print_targets')->insert([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino USB directo',
        'transport' => 'usb',
        'printer_profile' => 'default',
    ]))->toThrow(QueryException::class);

    // 2. Un socket de servidor con un endpoint USB tampoco entra: lo rechaza
    //    el CHECK del endpoint, que solo admite TCP 9100 o la impresora
    //    compartida de Windows.
    expect(fn () => DB::table('print_targets')->insert([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino USB por socket',
        'transport' => 'server_socket',
        'endpoint' => 'usb://localhost/printers/RAW',
        'printer_profile' => 'default',
    ]))->toThrow(QueryException::class);

    // 3. La impresora de RED por TCP 9100 si se admite.
    $red = DB::table('print_targets')->insertGetId([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de red',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.50:9100',
        'printer_profile' => 'default',
    ]);

    // 4. La impresora COMPARTIDA de Windows (recurso UNC) si se admite.
    $compartida = DB::table('print_targets')->insertGetId([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino compartido',
        'transport' => 'server_socket',
        'endpoint' => '\\\\HOST\\IMPRESORA',
        'printer_profile' => 'default',
    ]);

    // 5. El navegador NO tiene endpoint: renderiza HTML/PDF desde el mismo
    //    modelo de plantilla.
    $navegador = DB::table('print_targets')->insertGetId([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de navegador',
        'transport' => 'browser',
        'endpoint' => null,
        'printer_profile' => 'default',
    ]);

    expect($red)->toBeInt()
        ->and($compartida)->toBeInt()
        ->and($navegador)->toBeInt();

    // El destino admitido guarda el transporte y el endpoint tal cual.
    $guardado = DB::table('print_targets')->where('id', $compartida)->first();

    expect($guardado->transport)->toBe('server_socket')
        ->and($guardado->endpoint)->toBe('\\\\HOST\\IMPRESORA');
});
