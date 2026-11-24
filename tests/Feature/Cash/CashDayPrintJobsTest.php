<?php

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F3-02 — el Z que ENSEÑA el estado de sus trabajos de impresion.
 *
 * REGLA que fija: *el Z dice si su papel salio*.
 *
 * La pantalla de la caja del dia enseñaba CUANTAS veces se habia impreso el
 * Z (`print_count`), pero no el ESTADO de cada trabajo: el operador no
 * tenia manera de saber si el Z habia salido, si seguia en la cola o si
 * habia fallado. Un contador de impresiones no es el estado de las
 * impresiones.
 *
 * Y EL ESTADO NO SE INVENTA: es el que escribe la maquina de estados
 * (`queued`, `claimed`, `printing`, `printed`, `failed`), leido tal cual.
 *
 * Y SON LOS TRABAJOS DE ESE Z: los trabajos de los comprobantes de las
 * ventas NO son del Z, y un trabajo de otro Z tampoco. El Z se enlaza con su
 * trabajo por `cash_close_id`, que es lo que dice que documento imprime.
 *
 * Y NO SE TOCA LA LOGICA DE LA CAJA: esto solo ANADE un dato. La apertura,
 * el cierre, el descuadre y el Z sellado son los mismos de antes.
 */

/**
 * La caja del dia CERRADA, con su Z sellado.
 *
 * @param  TestCase  $prueba  El caso de prueba
 *                            que hace las peticiones.
 * @param  array<string, int>  $escenario
 * @return array{usuario: User, z_id: int} Quien cerro y el Z.
 */
function z_sellado_de_la_caja_del_dia($prueba, array $escenario): array
{
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $prueba->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Una VENTA: el esperado son 158 (100 de partida mas 58 de la venta).
    $prueba->actingAs($usuario)
        ->postJson('/caja/cobrar', [
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
        ])
        ->assertCreated();

    // Y el CIERRE, que CUADRA: sin descuadre no hace falta motivo.
    $prueba->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '158.0000'])
        ->assertCreated();

    return [
        'usuario' => $usuario,
        'z_id' => (int) DB::table('cash_closes')
            ->where('store_id', $escenario['store_id'])
            ->orderByDesc('id')
            ->value('id'),
    ];
}

/**
 * El Z ENSEÑA el estado de sus trabajos de impresion.
 *
 * REGLA que fija: *el Z dice el estado de cada trabajo suyo*. Un trabajo en
 * cola, un trabajo impreso y un trabajo fallado con su motivo: los tres se
 * ven, con el estado que escribio la maquina de estados.
 */
test('test_el_z_enseña_el_estado_de_sus_trabajos_de_impresion', function () {
    $escenario = PosFixture::escenario();

    $cerrada = z_sellado_de_la_caja_del_dia($this, $escenario);

    $zId = $cerrada['z_id'];
    $usuario = $cerrada['usuario'];

    $destino = PrintFixture::destino($escenario['store_id'], 'lan_agent', [
        'name' => 'Caja del Z',
    ]);

    // Un trabajo EN COLA del Z.
    $enCola = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'document_kind' => 'cash_close_z',
        'cash_close_id' => $zId,
        'status' => 'queued',
    ]);

    // Y uno FALLIDO, con su motivo: el fallo del Z es lo que hay que ver.
    $fallido = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'document_kind' => 'cash_close_z',
        'cash_close_id' => $zId,
        'status' => 'failed',
        'last_error' => 'La impresora no responde.',
        'attempts' => 2,
    ]);

    // Y uno IMPRESO.
    $impreso = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'document_kind' => 'cash_close_z',
        'cash_close_id' => $zId,
        'status' => 'printed',
    ]);

    // Y un trabajo de un COMPROBANTE de venta: NO es un trabajo del Z.
    $recibo = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
        'status' => 'queued',
    ]);

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('z.id', $zId)
            // Los TRES trabajos del Z, y solo ellos.
            ->where('z.print_jobs', fn ($trabajos): bool => count($trabajos) === 3)
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $enCola)['status'] === 'queued')
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallido)['status'] === 'failed')
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallido)['last_error'] === 'La impresora no responde.')
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $fallido)['attempts'] === 2)
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $impreso)['status'] === 'printed')
            ->where('z.print_jobs', fn ($trabajos): bool => collect($trabajos)->firstWhere('id', $recibo) === null)
            // Y el CONTADOR sigue being el mismo: cuantas veces se encolo el Z.
            ->where('z.print_count', 3)
            ->etc());
});

/**
 * El Z de un dia SIN trabajos no ensena trabajos, y la caja sigue igual.
 *
 * REGLA que fija: *anadir el estado de los trabajos NO cambia la logica de
 * la caja*. Un dia sin impresiones tiene un Z con cero trabajos, y sus
 * cifras son las de siempre.
 */
test('test_un_z_sin_trabajos_de_impresion_no_enseña_trabajos_y_la_caja_no_cambia', function () {
    $escenario = PosFixture::escenario();

    $cerrada = z_sellado_de_la_caja_del_dia($this, $escenario);

    $usuario = $cerrada['usuario'];

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'closed')
            ->where('z.print_jobs', [])
            ->where('z.print_count', 0)
            // Y las cifras de la caja, INTACTAS: el saldo en curso son los
            // 158 (100 de partida mas 58 de la venta) y el descuadre es cero.
            ->where('running_balance', '158.0000')
            ->where('expected_balance', '158.0000')
            ->where('counted_balance', '158.0000')
            ->where('difference', '0.0000')
            ->etc());
});
