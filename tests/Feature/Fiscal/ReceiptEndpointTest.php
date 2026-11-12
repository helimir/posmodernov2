<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 1.8 — el ENDPOINT del comprobante fiscal.
 *
 * REGLA que fija: *el comprobante es del DOCUMENTO, y el documento es de la
 * TIENDA*.
 *
 *  - sin sesion, el comprobante NO se sirve: redirige al login;
 *  - un documento de OTRA tienda es un 404: no se le dice a un cajero que el
 *    comprobante existe;
 *  - un documento en `draft` NO se sirve: un borrador no es un comprobante;
 *  - y el que se sirve lleva el numero de control y el total.
 */
uses(WithoutWrappingTransaction::class);

beforeEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

afterEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

/**
 * Una tienda con su usuario y un documento fiscal.
 *
 * @param  string  $estado  El estado del documento: `issued` o `draft`.
 * @return array<string, mixed>
 */
function escenarioDelComprobante(string $estado = 'issued'): array
{
    $currency = Currency::create([
        'code' => 'ZZA',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
    ]);

    $country = Country::create([
        'iso_code' => 'ZZ',
        'name' => 'Pais de prueba',
        'default_currency_id' => $currency->id,
    ]);

    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
        'default_currency_id' => $currency->id,
    ]);

    $store = Store::create([
        'name' => 'Tienda del comprobante',
        'address' => 'Calle 1, Caracas',
    ]);

    $otra = Store::create(['name' => 'Tienda ajena']);

    $usuario = User::factory()->create();

    DB::table('user_stores')->insert([
        'user_id' => $usuario->id,
        'store_id' => $store->id,
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $store->id,
        'document_type' => 'invoice',
        'status' => $estado,
        'number' => 77,
        'issue_date' => '2026-11-04',
        'issued_at' => $estado === 'issued' ? '2026-11-04 15:30:45+00' : null,
        'currency_id' => $currency->id,
        'subtotal' => '50.0000',
        'tax_total' => '8.0000',
        'total_amount' => '58.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fiscal_document_control_assignments')->insert([
        'fiscal_document_id' => $documentId,
        'control_number' => '00-00000077',
        'control_range_from' => 1,
        'control_range_to' => 9999,
        'control_assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentoAjeno = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $otra->id,
        'document_type' => 'invoice',
        'status' => 'issued',
        'number' => 88,
        'issue_date' => '2026-11-04',
        'issued_at' => '2026-11-04 15:30:45+00',
        'currency_id' => $currency->id,
        'subtotal' => '10.0000',
        'tax_total' => '0.0000',
        'total_amount' => '10.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'usuario' => $usuario,
        'store_id' => (int) $store->id,
        'document_id' => $documentId,
        'documento_ajeno' => $documentoAjeno,
    ];
}

/**
 * 1.8 — el comprobante se sirve con su numero de control y su total.
 */
test('test_el_comprobante_se_sirve_con_su_numero_de_control_y_su_total', function () {
    $escenario = escenarioDelComprobante();

    $respuesta = $this->actingAs($escenario['usuario'])
        ->get('/comprobantes/'.$escenario['document_id']);

    $respuesta->assertOk();

    $html = $respuesta->getContent();

    // 1. El numero de documento y el de control.
    expect($html)->toContain('N&#186; 77')
        ->and($html)->toContain('N&#186; Control 00-00000077');

    // 2. Y el total.
    expect($html)->toContain('Total: 58.0000');

    // 3. Y la ENVOLTURA: el papel de la impresora termica y el boton de
    //    imprimir, en ingles.
    expect($html)->toContain('80mm')
        ->and($html)->toContain('Print');
});

/**
 * 1.8 — sin sesion, el comprobante NO se sirve.
 */
test('test_sin_autenticar_el_comprobante_redirige', function () {
    $escenario = escenarioDelComprobante();

    $this->get('/comprobantes/'.$escenario['document_id'])->assertRedirect('/login');
});

/**
 * 1.8 — un documento de OTRA tienda es un 404.
 *
 * REGLA que fija: *no se le dice a un cajero que el comprobante existe*. Un
 * 403 ya diria que existe.
 */
test('test_un_comprobante_de_otra_tienda_es_un_404', function () {
    $escenario = escenarioDelComprobante();

    $this->actingAs($escenario['usuario'])
        ->get('/comprobantes/'.$escenario['documento_ajeno'])
        ->assertNotFound();
});

/**
 * 1.8 — un BORRADOR no es un comprobante, y no se sirve.
 */
test('test_un_comprobante_en_borrador_no_se_sirve', function () {
    $escenario = escenarioDelComprobante('draft');

    $this->actingAs($escenario['usuario'])
        ->get('/comprobantes/'.$escenario['document_id'])
        ->assertStatus(409);
});
