<?php

use App\Models\Account;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * El CAMINO del plan de cuentas, por HTTP de verdad: una venta cobrada con el
 * plan LEIDO DE LA BASE.
 *
 * Reglas que fija:
 *
 *  - la pantalla del plan se sirve con la sesion de verdad, y sin ella
 *    redirige al login;
 *  - editar el rol de una cuenta por la API funciona, y el cambio se ve en la
 *    pantalla;
 *  - una VENTA se cobra con el plan de la base: el asiento usa las cuentas
 *    del plan, no unas escritas a mano en el codigo;
 *  - APAGAR la caja y poner el Banco como caja se hace EN UN PASO: el plan
 *    no queda bloqueado.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS: un test con `actingAs()` verifica
 * la PAGINA. Este verifica el CAMINO: el arranque de verdad, el login de
 * verdad, el token CSRF de verdad y el enrutado real de las peticiones. El
 * servidor es de verdad (`php artisan serve`), en un puerto libre, contra una
 * base de datos propia que se tira al terminar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de_la_contabilidad(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La PAGINA de Inertia que devuelve el servidor: el componente y sus props.
 *
 * @return array<string, mixed>
 */
function pagina_de_inertia_de_la_contabilidad(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino completo: entrar, ver el plan, editar un rol y COBRAR con el
 * plan leido de la base.
 */
test('test_una_venta_por_http_cobra_con_el_plan_de_cuentas_leido_de_la_base', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada CON SU PLAN DE CUENTAS, su perfil fiscal, sus series, un
        //    producto con stock y un cajero con su vinculo por la tienda.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuario($escenario['store_id']);
        $cajero->email = 'contabilidad@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. SIN SESION, el plan NO se sirve: redirige al login.
        $sinSesion = $servidor->cliente(null, ['allow_redirects' => false])
            ->get($servidor->url('/contabilidad/cuentas'));

        expect($sinSesion->status())->toBe(302)
            ->and((string) $sinSesion->header('Location'))->toContain('/login');

        // 3. El login, con su token CSRF.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de_la_contabilidad($login->body());

        expect($token)->not->toBe('');

        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'contabilidad@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 4. La PANTALLA del plan: el componente y el plan de la tienda.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/contabilidad/cuentas'));

        expect($pagina->status())->toBe(200);

        $props = pagina_de_inertia_de_la_contabilidad($pagina->body());

        expect($props['component'] ?? null)->toBe('accounting/accounts')
            ->and($props['props']['missing_roles'] ?? null)->toBe([])
            ->and($props['props']['accounts'][0]['code'] ?? null)->not->toBeNull();

        // El token NUEVO: entrar regenera la sesion, y con ella el token.
        $token = token_csrf_de_la_contabilidad($pagina->body());

        expect($token)->not->toBe('');

        // 5. La EDICION del rol: la caja pasa a ser la cuenta del banco, y la
        //    caja se queda sin rol (el hueco se ve).
        $banco = Account::query()->where('code', '1110000')->firstOrFail();
        $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

        $edicion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->put($servidor->url('/api/contabilidad/cuentas/'.$banco->id.'/rol'), [
                'role' => 'cash',
            ]);

        // El rol 'cash' ya lo cumple la cuenta de efectivo: la API lo rechaza
        // con su mensaje.
        expect($edicion->status())->toBe(422)
            ->and($edicion->json('errorMsg'))->toContain("El rol 'cash' ya lo cumple la cuenta 1100000");

        // Y la operacion VALIDA: la caja se la queda la cuenta del banco.
        $edicion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->put($servidor->url('/api/contabilidad/cuentas/'.$efectivo->id.'/rol'), [
                'role' => null,
            ]);

        expect($edicion->status())->toBe(200)
            ->and($edicion->json('role'))->toBeNull()
            ->and($edicion->json('missing_roles'))->toBe(['cash']);

        $edicion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->put($servidor->url('/api/contabilidad/cuentas/'.$banco->id.'/rol'), [
                'role' => 'cash',
            ]);

        expect($edicion->status())->toBe(200)
            ->and($edicion->json('roles.cash'))->toBe('1110000')
            ->and($edicion->json('missing_roles'))->toBe([]);

        // 6. Y la VENTA: se cobra con el plan LEIDO de la base, y el asiento
        //    usa la cuenta que ahora es la caja.
        $cobro = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/cobrar'), [
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

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('grand_total'))->toBe('58.0000');

        // 7. Y el ASIENTO lleva la cuenta del plan: 1110000, la que ahora es
        //    la caja de la tienda.
        $cuentas = DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.store_id', $escenario['store_id'])
            ->distinct()
            ->pluck('ledger_lines.account_code')
            ->all();

        expect($cuentas)->toContain('1110000')
            ->and($cuentas)->toContain('4010000')
            ->and($cuentas)->toContain('2110000')
            ->and($cuentas)->not->toContain('1100000');
    } finally {
        $servidor->stop();
    }
});

/**
 * El CAMINO de la CAJA CAMBIADA, por HTTP de verdad: apagar la caja y poner
 * el Banco como caja, EN UN PASO.
 *
 * REGLA que fija este test: *una cuenta APAGADA no cumple ningun rol: sale del
 * plan*. El plan no puede quedarse bloqueado diciendo a la vez que le falta
 * el rol y que ya lo cumple una cuenta apagada: son dos metodos del mismo
 * dominio preguntando lo mismo, y tienen que responder lo mismo.
 */
test('test_apagar_la_caja_y_poner_el_banco_como_caja_se_hace_en_un_paso_por_http', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuario($escenario['store_id']);
        $cajero->email = 'cambio-de-caja@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. El login, con su token CSRF.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de_la_contabilidad($login->body());

        expect($token)->not->toBe('');

        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'cambio-de-caja@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 3. La PANTALLA del plan, y su token NUEVO.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/contabilidad/cuentas'));

        expect($pagina->status())->toBe(200);

        $token = token_csrf_de_la_contabilidad($pagina->body());

        expect($token)->not->toBe('');

        $efectivo = Account::query()->where('code', '1100000')->firstOrFail();
        $banco = Account::query()->where('code', '1110000')->firstOrFail();

        // 4. La caja se APAGA: el plan se queda SIN el rol 'cash'.
        $apagada = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/api/contabilidad/cuentas/'.$efectivo->id.'/desactivar'));

        expect($apagada->status())->toBe(200)
            ->and($apagada->json('is_active'))->toBeFalse()
            ->and($apagada->json('missing_roles'))->toBe(['cash']);

        // 5. Y el BANCO puede ser la caja EN EL MISMO PASO: el plan NO queda
        //    bloqueado.
        $asignada = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->put($servidor->url('/api/contabilidad/cuentas/'.$banco->id.'/rol'), [
                'role' => 'cash',
            ]);

        expect($asignada->status())->toBe(200)
            ->and($asignada->json('roles.cash'))->toBe('1110000')
            ->and($asignada->json('missing_roles'))->toBe([]);

        // 6. Y la pantalla dice que el plan esta COMPLETO, con la caja nueva.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/contabilidad/cuentas'));

        expect($pagina->status())->toBe(200);

        $props = pagina_de_inertia_de_la_contabilidad($pagina->body());
        $cuentas = collect($props['props']['accounts'] ?? []);

        $caja = $cuentas->firstWhere('code', '1100000');
        $banco = $cuentas->firstWhere('code', '1110000');

        expect($props['props']['missing_roles'] ?? null)->toBe([])
            ->and($banco)->not->toBeNull()
            ->and($banco['role'])->toBe('cash')
            // Y la caja apagada ya no cumple NINGUN rol.
            ->and($caja)->not->toBeNull()
            ->and($caja['role'])->toBeNull();
    } finally {
        $servidor->stop();
    }
});
