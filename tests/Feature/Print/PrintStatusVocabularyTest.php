<?php

use App\Print\PrintQueueOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F3-02 — el ESTADO DEL TICKET en la caja.
 *
 * REGLA que fija: *la caja ENSENA el estado del ticket, y su motivo es un
 * codigo CERRADO*.
 *
 * El cobro ya respondia con el bloque `print` (`status`, `job_id`, `reason`,
 * `message`), pero la pantalla de la caja NO lo consumia: el cajero veia
 * "vendi" y no veia si el papel habia salido. Y un ticket que no se encolo
 * tiene un MOTIVO, y ese motivo es un CODIGO CERRADO: la pantalla lo
 * traduce, y un motivo suelto obligaria a la pantalla a adivinar.
 *
 * Y LA PANTALLA TIENE QUE PODER TRADUCIRLOS TODOS: si el dominio declara un
 * motivo nuevo y la pantalla no lo traduce, el cajero veria un codigo crudo.
 * Por eso el vocabulario de la pantalla se comprueba contra el del dominio:
 * los dos no pueden separarse.
 *
 * POR QUE ESTE FICHERO LEE EL CODIGO DE LA PANTALLA: el repositorio no
 * tiene ejecutor de pruebas del frontend, asi que el contrato de la pantalla
 * se fija leyéndola. Es la unica forma de que la regla "la caja ensena el
 * estado del ticket" quede en la suite y no solo en la intention.
 */

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedido_con_estado_de_ticket(array $escenario): array
{
    return [
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
    ];
}

/**
 * El COBRO siempre responde con el estado del ticket, y el motivo es un
 * codigo del dominio.
 *
 * REGLA que fija: *el cobro NUNCA se calla sobre el papel*. La respuesta
 * dice si el ticket se encolo (con el identificador del trabajo) o por que
 * no se encolo (con el motivo), y el motivo es UNO de los codigos que el
 * dominio declara.
 */
test('test_el_cobro_siempre_responde_con_el_estado_del_ticket_y_el_motivo_es_un_codigo_cerrado', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();

    $cajero = PosFixture::usuario($escenario['store_id']);

    // 1. La tienda IMPRIME y tiene UN destino: el ticket se encola.
    DB::table('store_settings')
        ->where('store_id', $escenario['store_id'])
        ->update(['auto_print' => true, 'updated_at' => now()]);

    PrintFixture::destino($escenario['store_id'], 'lan_agent', [
        'name' => 'Caja de la tienda',
    ]);

    $cobro = $this->actingAs($cajero)
        ->postJson('/caja/cobrar', pedido_con_estado_de_ticket($escenario));

    $cobro->assertCreated();

    // El bloque `print` esta SIEMPRE, con sus cuatro claves.
    expect($cobro->json('print'))->toBeArray()
        ->and($cobro->json('print'))->toHaveKeys(['status', 'job_id', 'reason', 'message']);

    // ENCOLADO: con su trabajo, y sin motivo.
    expect((string) $cobro->json('print.status'))->toBe(PrintQueueOutcome::ENCOLADO)
        ->and($cobro->json('print.job_id'))->not->toBeNull()
        ->and($cobro->json('print.reason'))->toBeNull();

    // Y el trabajo EXISTE, en la cola, con sus bytes congelados.
    $jobId = (int) $cobro->json('print.job_id');

    expect((string) DB::table('print_jobs')->where('id', $jobId)->value('status'))->toBe('queued');

    // 2. Y si la tienda NO imprime, el cobro lo DICE, con su motivo.
    DB::table('store_settings')
        ->where('store_id', $escenario['store_id'])
        ->update(['auto_print' => false, 'updated_at' => now()]);

    $sinImprimir = $this->actingAs($cajero)
        ->postJson('/caja/cobrar', pedido_con_estado_de_ticket($escenario));

    $sinImprimir->assertCreated();

    expect((string) $sinImprimir->json('print.status'))->toBe(PrintQueueOutcome::NO_ENCOLADO)
        ->and($sinImprimir->json('print.job_id'))->toBeNull()
        ->and((string) $sinImprimir->json('print.reason'))->toBe(PrintQueueOutcome::AUTO_PRINT_APAGADO)
        ->and((string) $sinImprimir->json('print.message'))->not->toBe('');

    // Y el motivo es UNO de los codigos que el dominio declara: el conjunto es
    // CERRADO, y la pantalla traduce ese conjunto.
    expect(PrintQueueOutcome::motivos())->toContain((string) $sinImprimir->json('print.reason'));
});

/**
 * La PANTALLA de la caja traduce TODOS los motivos del dominio.
 *
 * REGLA que fija: *la pantalla y el dominio no pueden separarse*. Si el
 * dominio declara un motivo nuevo, la pantalla tiene que saber leerlo: un
 * motivo sin traducir es un codigo crudo en la cara del cajero.
 */
test('test_la_caja_traduce_todos_los_motivos_del_dominio', function () {
    $vocabulario = base_path('resources/js/lib/print.ts');

    expect(file_exists($vocabulario))->toBeTrue(
        'La pantalla no tiene el vocabulario de la impresion: el estado del ticket no se puede enseñar.'
    );

    $contenido = (string) file_get_contents($vocabulario);

    foreach (PrintQueueOutcome::motivos() as $motivo) {
        expect($contenido)->toContain("'".$motivo."'");
    }

    // Y tambien los dos ESTADOS del ticket: encolado y no encolado.
    foreach (PrintQueueOutcome::estados() as $estado) {
        expect($contenido)->toContain("'".$estado."'");
    }
});

/**
 * La CAJA ENSENA el estado del ticket, y si no se encolo lo dice.
 *
 * REGLA que fija: *el cajero ve si el papel salio*. La pantalla de la caja
 * consume el bloque `print` del cobro y lo ensena; y cuando el ticket NO se
 * encolo, ensena el motivo: un ticket que no sale y no se explica es un
 * ticket que se pierde.
 */
test('test_la_caja_enseña_el_estado_del_ticket_y_no_se_lo_traga', function () {
    $caja = base_path('resources/js/pages/pos/index.tsx');

    expect(file_exists($caja))->toBeTrue('La pantalla de la caja no existe.');

    $contenido = (string) file_get_contents($caja);

    // 1. El CONTRATO de la venta incluye el estado del ticket.
    expect($contenido)->toContain('print: PrintQueueStatus');

    // 2. Y la pantalla lo ENSEÑA: el estado, con su etiqueta y su color.
    expect($contenido)->toContain('sale.print.status')
        ->and($contenido)->toContain('printLabel(sale.print.status)')
        ->and($contenido)->toContain('printVariant(sale.print.status)');

    // 3. Y cuando NO se encolo, ensena el MOTIVO: no se traga el motivo.
    expect($contenido)->toContain('sale.print.reason')
        ->and($contenido)->toContain('sale.print.message');

    // 4. Y ENLAZA a la cola de trabajos, que es donde se ve el estado real.
    expect($contenido)->toContain('/impresion/trabajos');
});
