<?php

use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-12 — el Z IMPRIMIBLE: el cierre de caja en papel y en ESC/POS.
 *
 * Reglas que fija:
 *
 *  - el Z se imprime con el contenido EXACTO del Z SELLADO: no se recalcula, y
 *    una mutacion de la base DESPUES de sellarlo no cambia lo que sale
 *    impreso;
 *  - un dia ABIERTO no tiene Z: la ruta no lo sirve, porque un dia abierto no
 *    es un hecho cerrado;
 *  - el Z en ESC/POS lleva las MISMAS cifras que el Z en HTML, y CABE en el
 *    ancho que declara el destino;
 *  - reimprimir un Z deja CONSTANCIA: el motivo y el enlace al Z.
 */

/**
 * El CATALOGO de denominaciones de la tienda del test: es DATO, y lo escribe
 * el propio test.
 *
 * @return array<string, int> El id de cada denominacion, por valor.
 */
function z_catalogo(int $storeId, int $currencyId): array
{
    $ids = [];

    foreach ([
        ['100.0000', 'billete'],
        ['50.0000', 'billete'],
        ['5.0000', 'billete'],
        ['1.0000', 'moneda'],
        ['0.5000', 'moneda'],
        ['0.1000', 'moneda'],
        ['0.0500', 'moneda'],
    ] as [$valor, $tipo]) {
        $ids[$valor] = (int) DB::table('cash_denominations')->insertGetId([
            'store_id' => $storeId,
            'currency_id' => $currencyId,
            'value' => $valor,
            'kind' => $tipo,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $ids;
}

/**
 * El DIA CERRADO del test: se abre la caja, se cobra una venta y se cierra
 * POR PIEZAS, con un descuadre y su motivo.
 *
 * @param  mixed  $test  El caso de la prueba, para poder actuar como el
 *                       usuario.
 * @return array<string, mixed>
 */
function z_diaCerrado(mixed $test): array
{
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $codigo = (new PosContext(DB::connection(), (int) $usuario->id))->currencyCode();

    $moneda = DB::table('currencies')->where('code', $codigo)->first();

    $ids = z_catalogo($escenario['store_id'], (int) $moneda->id);

    $test->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Una VENTA: el Z necesita totales, pagos y correlativos.
    $test->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [[
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ]],
            'payments' => [[
                'payment_method_id' => $escenario['payment_method_id'],
                'amount' => '58.0000',
            ]],
        ])
        ->assertCreated();

    // El CIERRE por PIEZAS: 159 (sobra una moneda de uno) y su MOTIVO.
    $test->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'counted_pieces' => [
                (string) $ids['100.0000'] => 1,
                (string) $ids['50.0000'] => 1,
                (string) $ids['5.0000'] => 1,
                (string) $ids['1.0000'] => 4,
            ],
            'reason' => 'Sobra una moneda de uno en el conteo',
        ])
        ->assertCreated();

    $z = DB::table('cash_closes')
        ->where('store_id', $escenario['store_id'])
        ->orderBy('id', 'desc')
        ->first();

    return [
        'escenario' => $escenario,
        'usuario' => $usuario,
        'tienda' => (string) DB::table('stores')->where('id', $escenario['store_id'])->value('name'),
        'moneda' => $moneda,
        'ids' => $ids,
        'z' => $z,
    ];
}

/**
 * Un importe como lo ENSENA el Z: a la escala de la moneda de la tienda.
 */
function z_monto(string $decimal, int $decimales): string
{
    $partes = explode('.', $decimal);
    $entera = $partes[0];

    if ($decimales === 0) {
        return $entera;
    }

    $fraccion = str_pad(substr($partes[1] ?? '', 0, $decimales), $decimales, '0');

    return $entera.'.'.$fraccion;
}

/**
 * Las CELDAS de una fila del Z en HTML: la fila se busca por su `data-field`.
 *
 * @return list<string>
 */
function z_celdas(string $html, string $campo): array
{
    $patron = '/<tr[^>]*data-field="'.preg_quote($campo, '/').'"[^>]*>(.*?)<\/tr>/s';

    if (preg_match($patron, $html, $coincidencia) !== 1) {
        return [];
    }

    preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $coincidencia[1], $celdas);

    return array_map(
        static fn (string $celda): string => trim(html_entity_decode(strip_tags($celda), ENT_QUOTES, 'UTF-8')),
        $celdas[1] ?? []
    );
}

/**
 * Las LINEAS del modelo de un trabajo de impresion: lo que se imprime.
 *
 * @return list<string>
 */
function z_lineas(int $targetId): array
{
    $fila = DB::table('print_jobs')
        ->where('print_target_id', $targetId)
        ->orderBy('id', 'desc')
        ->first();

    if ($fila === null) {
        return [];
    }

    $modelo = json_decode((string) $fila->payload, true);

    return array_map(
        static fn (array $linea): string => (string) ($linea['text'] ?? ''),
        $modelo['lines'] ?? []
    );
}

/**
 * El Z EN PAPEL lleva el contenido SELLADO: los saldos, el descuadre, los
 * totales, el desglose por medio de pago, el conteo por denominacion, los
 * correlativos, quien cerro y la FIRMA del Z.
 */
test('test_el_z_en_papel_lleva_el_contenido_sellado_del_z', function () {
    $ctx = z_diaCerrado($this);
    $z = $ctx['z'];
    $decimales = (int) $ctx['moneda']->decimals;

    $html = $this->actingAs($ctx['usuario'])
        ->get('/caja/dia/z')
        ->assertOk()
        ->getContent();

    // La IDENTIDAD del Z: la denominacion, la tienda y la fecha de negocio.
    expect($html)->toContain('Closing report (Z)')
        ->and(z_celdas($html, 'store'))->toBe(['Store', $ctx['tienda']])
        ->and(z_celdas($html, 'business_date'))->toContain((string) $z->business_date);

    // Los SALDOS: la partida, el esperado, el contado y el DESCUADRE.
    expect(z_celdas($html, 'opening_balance'))->toContain(z_monto('100.0000', $decimales))
        ->and(z_celdas($html, 'expected_balance'))->toContain(z_monto('158.0000', $decimales))
        ->and(z_celdas($html, 'counted_balance'))->toContain(z_monto('159.0000', $decimales))
        ->and(z_celdas($html, 'difference'))->toContain('+'.z_monto('1.0000', $decimales));

    // Los TOTALES del dia y el numero de ventas.
    expect(z_celdas($html, 'subtotal'))->toContain(z_monto('50.0000', $decimales))
        ->and(z_celdas($html, 'tax_total'))->toContain(z_monto('8.0000', $decimales))
        ->and(z_celdas($html, 'grand_total'))->toContain(z_monto('58.0000', $decimales))
        ->and(z_celdas($html, 'sales_count'))->toContain('1');

    // El DESGLOSE por medio de pago.
    expect(z_celdas($html, 'payment_'.$ctx['escenario']['payment_method_id']))
        ->toContain('Efectivo')
        ->toContain(z_monto('58.0000', $decimales));

    // El CONTEO por DENOMINACION: el valor de la pieza, cuantas y cuanto dinero.
    expect(z_celdas($html, 'denomination_'.$ctx['ids']['100.0000']))
        ->toBe([z_monto('100.0000', $decimales), '1', z_monto('100.0000', $decimales)])
        ->and(z_celdas($html, 'denomination_'.$ctx['ids']['1.0000']))
        ->toBe([z_monto('1.0000', $decimales), '4', z_monto('4.0000', $decimales)]);

    // Los CORRELATIVOS del dia: el primer y el ultimo documento, y el
    // primer y el ultimo control.
    expect(z_celdas($html, 'documents'))->toContain((string) $z->first_document_number)
        ->and(z_celdas($html, 'control'))->toContain((string) $z->first_control_number);

    // QUIEN CERRO, CUANDO, POR QUE y la FIRMA del Z.
    expect(z_celdas($html, 'closed_by'))->toContain((string) $ctx['usuario']->name)
        ->and(z_celdas($html, 'closed_at'))->not->toBeEmpty()
        ->and(z_celdas($html, 'close_reason'))->toContain('Sobra una moneda de uno en el conteo')
        ->and(z_celdas($html, 'signature'))->toContain((string) $z->content_hash);
});

/**
 * Un dia ABIERTO no tiene Z: la ruta no lo sirve. Un dia abierto no es un
 * hecho cerrado, y no hay nada sellado que imprimir.
 */
test('test_un_dia_abierto_no_tiene_z_y_la_ruta_no_lo_sirve', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->get('/caja/dia/z')
        ->assertNotFound();

    // Y tampoco hay trabajo de impresion: no se puede encolar lo que no hay.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * El Z IMPRESO lleva el contenido SELLADO, no un recalculo: una mutacion de
 * la base DESPUES del cierre no cambia lo que sale impreso.
 *
 * Y si se ALTERA el Z sellado, la impresion se NIEGA: su firma ya no cuadra.
 */
test('test_el_z_impreso_lleva_el_contenido_sellado_y_no_un_recalculo', function () {
    $ctx = z_diaCerrado($this);
    $decimales = (int) $ctx['moneda']->decimals;

    // La MUTACION: la venta se corrige DESPUES del cierre. Un Z RECALCULADO
    // daria estos numeros; el Z SELLADO, no.
    DB::table('sale_totals')->update([
        'sub_total' => '900.0000',
        'tax_total' => '144.0000',
        'grand_total' => '1044.0000',
    ]);

    $cuentaEfectivo = (string) DB::table('store_accounts')
        ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
        ->where('store_accounts.store_id', $ctx['escenario']['store_id'])
        ->where('store_accounts.role', 'cash')
        ->value('accounts.code');

    DB::table('ledger_lines')
        ->where('account_code', $cuentaEfectivo)
        ->update(['debit' => DB::raw('debit + 1000.0000')]);

    $html = $this->actingAs($ctx['usuario'])
        ->get('/caja/dia/z')
        ->assertOk()
        ->getContent();

    // El Z sigue diciendo lo que DIJO AL SELLARSE.
    expect(z_celdas($html, 'expected_balance'))->toContain(z_monto('158.0000', $decimales))
        ->and(z_celdas($html, 'counted_balance'))->toContain(z_monto('159.0000', $decimales))
        ->and(z_celdas($html, 'grand_total'))->toContain(z_monto('58.0000', $decimales))
        ->and($html)->not->toContain('1044.00');

    // Y si se ALTERA el Z SELLADO, la impresion se NIEGA: la firma no cuadra.
    DB::table('cash_closes')
        ->where('id', (int) $ctx['z']->id)
        ->update(['grand_total' => '999.0000']);

    $this->actingAs($ctx['usuario'])
        ->get('/caja/dia/z')
        ->assertStatus(409);
});

/**
 * El Z en ESC/POS lleva las MISMAS cifras que el Z en HTML, y CABE en el
 * ancho que declara el destino (32 caracteres es una termica de 58 mm).
 */
test('test_el_z_en_escpos_lleva_las_mismas_cifras_que_el_z_en_html_y_cabe_en_el_ancho', function () {
    $ctx = z_diaCerrado($this);
    $decimales = (int) $ctx['moneda']->decimals;

    $destino = PrintFixture::destino($ctx['escenario']['store_id'], 'lan_agent', [
        'name' => 'Z de 32',
        'char_per_line' => 32,
    ]);

    $this->actingAs($ctx['usuario'])
        ->postJson('/caja/dia/z/imprimir', ['print_target_id' => $destino['target_id']])
        ->assertCreated();

    $lineas = z_lineas($destino['target_id']);

    expect($lineas)->not->toBeEmpty();

    // Las CIFRAS: las mismas que en el papel.
    $texto = implode("\n", $lineas);

    expect($texto)->toContain(z_monto('158.0000', $decimales))
        ->toContain(z_monto('159.0000', $decimales))
        ->toContain(z_monto('58.0000', $decimales))
        ->toContain(z_monto('50.0000', $decimales))
        ->toContain(z_monto('1.0000', $decimales));

    // La FIRMA del Z: la misma, partida en las lineas que el ancho permite.
    $indice = null;

    foreach ($lineas as $posicion => $linea) {
        if (str_contains($linea, 'Signature (sha256)')) {
            $indice = $posicion;
        }
    }

    expect($indice)->not->toBeNull();

    expect(implode('', array_slice($lineas, $indice + 1)))
        ->toBe((string) $ctx['z']->content_hash);

    // Y CADA LINEA cabe en el ancho declarado: el Z se adapta al papel.
    foreach ($lineas as $linea) {
        expect(mb_strlen($linea))->toBeLessThanOrEqual(32);
    }
});

/**
 * Un destino demasiado ANGOSTO para el Z falla con un mensaje que dice QUE
 * hace falta, en vez de cortar el Z en silencio.
 */
test('test_un_destino_demasiado_angosto_para_el_z_falla_con_un_mensaje_util', function () {
    $ctx = z_diaCerrado($this);

    $destino = PrintFixture::destino($ctx['escenario']['store_id'], 'lan_agent', [
        'name' => 'Z de 12',
        'char_per_line' => 12,
    ]);

    $respuesta = $this->actingAs($ctx['usuario'])
        ->postJson('/caja/dia/z/imprimir', ['print_target_id' => $destino['target_id']])
        ->assertStatus(422);

    expect((string) $respuesta->json('errorMsg'))
        ->toContain('12')
        ->toContain('Z');

    // Y NO se crea el trabajo: un Z que no cabe no sale a medias.
    expect(DB::table('print_jobs')->where('print_target_id', $destino['target_id'])->count())->toBe(0);
});

/**
 * REIMPRIMIR un Z deja CONSTANCIA: el motivo y el enlace al Z. Y un trabajo
 * de Z se distingue de uno de comprobante.
 */
test('test_reimprimir_el_z_deja_constancia_del_motivo_y_del_z', function () {
    $ctx = z_diaCerrado($this);

    $destino = PrintFixture::destino($ctx['escenario']['store_id'], 'lan_agent', [
        'name' => 'Z de la caja',
        'char_per_line' => 42,
    ]);

    // La PRIMERA impresion: no hay motivo, porque no es una reimpresion.
    $primera = $this->actingAs($ctx['usuario'])
        ->postJson('/caja/dia/z/imprimir', ['print_target_id' => $destino['target_id']])
        ->assertCreated();

    $jobId = (int) $primera->json('job_id');

    $primero = DB::table('print_jobs')->where('id', $jobId)->first();

    expect((string) $primero->document_kind)->toBe('cash_close_z')
        ->and((int) $primero->cash_close_id)->toBe((int) $ctx['z']->id)
        ->and($primero->reprint_of_job_id)->toBeNull()
        ->and($primero->reason)->toBeNull();

    // La SEGUNDA impresion SIN motivo: falla. Un Z reimpreso deja constancia
    // de POR QUE se reimprime.
    $this->actingAs($ctx['usuario'])
        ->postJson('/caja/dia/z/imprimir', ['print_target_id' => $destino['target_id']])
        ->assertStatus(422);

    // Y CON motivo: sale, y el trabajo dice POR QUE y a QUE Z.
    $segunda = $this->actingAs($ctx['usuario'])
        ->postJson('/caja/dia/z/imprimir', [
            'print_target_id' => $destino['target_id'],
            'reason' => 'La impresora se atascó a media hoja',
        ])
        ->assertCreated();

    $reimpresion = DB::table('print_jobs')->where('id', (int) $segunda->json('job_id'))->first();

    expect((string) $reimpresion->document_kind)->toBe('cash_close_z')
        ->and((int) $reimpresion->cash_close_id)->toBe((int) $ctx['z']->id)
        ->and((int) $reimpresion->reprint_of_job_id)->toBe($jobId)
        ->and((string) $reimpresion->reason)->toBe('La impresora se atascó a media hoja');

    // Y la AUDIT deja la constancia del reimpreso, con el motivo y el Z.
    $auditorias = DB::table('audit_logs')
        ->where('action', 'cash_close.reprinted')
        ->where('entity_id', (int) $ctx['z']->id)
        ->get();

    expect($auditorias)->toHaveCount(1)
        ->and((string) $auditorias->first()->context)->toContain('La impresora se atascó a media hoja');
});
