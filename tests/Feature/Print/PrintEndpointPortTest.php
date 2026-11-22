<?php

use App\Models\Printer;
use App\Models\Store;
use App\Print\PrintEndpoint;
use App\Print\PrintTransport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * El PUERTO del destino de impresion: el que tenga la impresora.
 *
 * Reglas que fija:
 *
 *  - un destino de impresion es una impresora de RED en el PUERTO que tenga
 *    (9105, 515, 631...), o la impresora compartida de Windows. El 9100 es el
 *    RAW de facto del estandar ESC/POS, pero no es una ley: un POS que no
 *    puede configurar su impresora es un POS que se queda sin ticket;
 *  - el USB DIRECTO sigue rechazandose (en Windows exige Zadig), y el esquema
 *    `file://` tambien: un destino no es un fichero local;
 *  - el `browser` sigue SIN endpoint: el navegador ES el destino.
 *
 * Y EL ESQUEMA Y EL DOMINIO DICEN LO MISMO: el CHECK
 * (`print_targets_endpoint_admitted`) es la red de seguridad, y `PrintEndpoint`
 * es el mensaje. Los dos admiten el puerto que sea, y los dos rechazan el USB.
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
function codigo_de_destino(string $prefijo = 'IMP'): string
{
    return $prefijo.'-'.strtoupper(substr(md5(uniqid('', true)), 0, 10));
}

/**
 * Un PUERTO cualquiera es admisible: el de la impresora de red.
 *
 * REGLA que fija este test: *el puerto es el que tenga la impresora*. El
 * 9100 es el RAW de facto del estandar ESC/POS, pero el 9105, el 515 (LPD) y
 * el 631 (IPP) son puertos de impresora de red tan reales como el 9100.
 */
test('test_un_puerto_cualquiera_es_admisible_en_la_impresora_de_red', function () {
    foreach (['9100', '9105', '515', '631', '1', '65535'] as $puerto) {
        $endpoint = PrintEndpoint::deTexto('tcp://192.168.1.50:'.$puerto);

        expect($endpoint->esSocket())->toBeTrue()
            ->and($endpoint->puerto)->toBe((int) $puerto)
            ->and($endpoint->direccionDeSocket())->toBe('tcp://192.168.1.50:'.$puerto);
    }
});

/**
 * El USB DIRECTO y el esquema `file://` SIGUEN rechazandose.
 *
 * REGLA que fija este test: *lo que se afloja es el PUERTO, y nada mas*. El
 * USB directo exige Zadig en Windows, y un fichero local no es un destino.
 */
test('test_el_usb_directo_y_el_esquema_de_fichero_siguen_rechazandose', function () {
    // 1. El USB directo: el esquema lo rechaza.
    expect(fn () => PrintEndpoint::deTexto('usb://localhost/printers/RAW'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');

    // 2. El esquema `file://` tampoco.
    expect(fn () => PrintEndpoint::deTexto('file:///dev/usb/lp0'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');

    // 3. Y un puerto que NO es de TCP tampoco.
    expect(fn () => PrintEndpoint::deTexto('tcp://192.168.1.50:0'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');

    expect(fn () => PrintEndpoint::deTexto('tcp://192.168.1.50:65536'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');
});

/**
 * El `browser` sigue SIN endpoint: el navegador ES el destino.
 *
 * REGLA que fija este test: *el transporte decide si hay endpoint*. Los
 * transportes fisicos lo tienen SIEMPRE, y el navegador no lo tiene NUNCA.
 */
test('test_el_browser_sigue_sin_endpoint', function () {
    expect(PrintEndpoint::paraTransporte(PrintTransport::Browser, null))->toBeNull();

    expect(fn () => PrintEndpoint::paraTransporte(PrintTransport::Browser, 'tcp://192.168.1.50:9100'))
        ->toThrow(RuntimeException::class, 'no declara endpoint');

    // Y los transportes FISICOS lo exigen siempre.
    expect(fn () => PrintEndpoint::paraTransporte(PrintTransport::ServerSocket, null))
        ->toThrow(RuntimeException::class, 'no declara endpoint');

    expect(PrintEndpoint::paraTransporte(PrintTransport::ServerSocket, 'tcp://192.168.1.50:9105'))
        ->toBe('tcp://192.168.1.50:9105');
});

/**
 * El CHECK del ESQUEMA admite el puerto que sea, y sigue rechazando el USB.
 *
 * REGLA que fija este test: *el esquema y el dominio no pueden divergir*. El
 * CHECK es la red de seguridad: si el dominio admite un destino, el esquema lo
 * admite, y si el esquema lo rechaza, el dominio tambien.
 */
test('test_el_check_del_esquema_admite_cualquier_puerto_y_sigue_rechazando_el_usb', function () {
    $printer = Printer::create([
        'code' => codigo_de_destino(),
        'name' => 'Impresora del puerto libre',
    ]);

    $store = Store::create(['name' => 'Tienda del puerto libre '.codigo_de_destino('TIENDA')]);

    // 1. Un destino de RED en un puerto que no es el 9100: el CHECK lo
    //    ADMITE.
    $red = DB::table('print_targets')->insertGetId([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de red en el 9105',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.50:9105',
        'printer_profile' => 'default',
    ]);

    // 2. Y el USB directo por socket: el CHECK lo RECHAZA.
    expect(fn () => DB::table('print_targets')->insert([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino USB por socket',
        'transport' => 'server_socket',
        'endpoint' => 'usb://localhost/printers/RAW',
        'printer_profile' => 'default',
    ]))->toThrow(QueryException::class);

    // 3. Y el esquema `file://` tambien.
    expect(fn () => DB::table('print_targets')->insert([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de fichero',
        'transport' => 'server_socket',
        'endpoint' => 'file:///dev/usb/lp0',
        'printer_profile' => 'default',
    ]))->toThrow(QueryException::class);

    // 4. Y un puerto de mas de 65535 tampoco.
    expect(fn () => DB::table('print_targets')->insert([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de puerto imposible',
        'transport' => 'server_socket',
        'endpoint' => 'tcp://192.168.1.50:65536',
        'printer_profile' => 'default',
    ]))->toThrow(QueryException::class);

    // 5. El navegador sigue sin endpoint.
    $navegador = DB::table('print_targets')->insertGetId([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino de navegador',
        'transport' => 'browser',
        'endpoint' => null,
        'printer_profile' => 'default',
    ]);

    expect($red)->toBeInt()
        ->and($navegador)->toBeInt()
        ->and((string) DB::table('print_targets')->where('id', $red)->value('endpoint'))
        ->toBe('tcp://192.168.1.50:9105');
});
