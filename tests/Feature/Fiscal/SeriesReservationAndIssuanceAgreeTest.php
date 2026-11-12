<?php

use Database\Seeders\FiscalProviderSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * 1.13 — la serie que RESERVA el cobro y la que EMITE el comprobante son la
 * MISMA.
 *
 * EL RIESGO que esta prueba fija: *la reserva y la emision no pueden mirar
 * cosas distintas*. La serie la elige el `PosContext` (T1 la reserva) y la
 * usa el emisor (T2 la resuelve), y si cada una mira su propia relacion, la
 * venta usa una serie y el comprobante otro.
 *
 * POR QUE NO SE ROMPEN: el emisor NO busca la serie de la tienda: busca la
 * serie de la RESERVA (`fiscal_document_reservations.series_id`). Con la
 * resolucion del perfil corregida, la reserva es de la serie del perfil de la
 * tienda, y el emisor hereda de la reserva esa misma serie. La garantia no
 * es de confianza: es de la fila.
 *
 * Y LA PRUEBA ES CON UN PERFIL PROPIO: la tienda DECLARA un perfil que no es
 * el de su pais, con su propia serie y su propia tasa. Con el codigo
 * defectuoso (el pais por su identificador), la venta usaria la serie del
 * perfil del pais, y las reservas del comprobante saldrian de otro perfil.
 */
beforeEach(function () {
    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);

    // El PERFIL PROPIO de la tienda: uno del mismo pais, con su categoria
    // fiscal, su tasa y sus DOS series.
    $this->perfilPropioId = acuerdo_perfil_propio($this->escenario);

    // Y la tienda lo DECLARA.
    DB::table('stores')->where('id', $this->escenario['store_id'])->update([
        'fiscal_profile_id' => $this->perfilPropioId,
        'updated_at' => now(),
    ]);
});

/**
 * El PERFIL PROPIO de la tienda: del mismo pais que el del escenario, pero
 * con su propia categoria fiscal, su propia tasa y sus dos series.
 *
 * @param  array<string, int>  $escenario
 */
function acuerdo_perfil_propio(array $escenario): int
{
    $perfilId = DB::table('fiscal_profiles')->insertGetId([
        'country_id' => DB::table('stores')->where('id', $escenario['store_id'])->value('country_id'),
        'code' => 'VE_PROPIO',
        'name' => 'Regimen propio de la tienda',
        'signer_model' => 'printer',
        'rounding_rule' => 'line',
        'tax_inclusive_default' => false,
        'default_currency_id' => DB::table('fiscal_profiles')
            ->where('id', $escenario['profile_id'])
            ->value('default_currency_id'),
        'requires_control_number' => true,
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La CATEGORIA FISCAL del perfil propio, con su tasa: el 10 %, para que
    // el total de la venta diga QUE perfil se aplico.
    $categoriaId = DB::table('tax_categories')->insertGetId([
        'fiscal_profile_id' => $perfilId,
        'code' => 'propia',
        'name' => 'Gravada del regimen propio',
        'is_withholding' => false,
        'applies_to' => 'both',
        'evidence_status' => 'pending_confirmation',
        'source_ref' => 'Fila de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('tax_rates')->insert([
        'tax_category_id' => $categoriaId,
        'percentage' => '10.000000',
        'rounding_rule' => null,
        'valid_from' => '2020-01-01',
        'valid_to' => null,
        'priority' => 1,
        'evidence_status' => 'pending_confirmation',
        'source_ref' => 'Fila de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // El VOCABULARIO y las CLAVES del perfil: son los del perfil del
    // escenario, y el emisor los necesita para consolidar el comprobante.
    DB::statement(
        'INSERT INTO fiscal_status_vocabularies (fiscal_profile_id, native_code, native_label,'
        .' generic_status, is_final, is_cancellation, created_at, updated_at)'
        .' SELECT ?, native_code, native_label, generic_status, is_final, is_cancellation, now(), now()'
        .' FROM fiscal_status_vocabularies WHERE fiscal_profile_id = ?',
        [$perfilId, $escenario['profile_id']]
    );

    DB::statement(
        'INSERT INTO fiscal_profile_fields (fiscal_profile_id, "key", data_type, label, value,'
        .' required, applies_to, evidence_status, source_ref, created_at, updated_at)'
        .' SELECT ?, "key", data_type, label, value, required, applies_to, evidence_status, source_ref,'
        .' now(), now() FROM fiscal_profile_fields WHERE fiscal_profile_id = ?',
        [$perfilId, $escenario['profile_id']]
    );

    // El CANAL: el proveedor local y su entorno, para el perfil propio.
    (new FiscalProviderSeeder)->run();

    return (int) $perfilId;
}

/**
 * La SERIE del DOCUMENTO del perfil propio, en la tienda del escenario.
 *
 * @param  array<string, int>  $escenario
 */
function acuerdo_serie_documento(array $escenario, int $perfilId): int
{
    return DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $perfilId,
        'store_id' => $escenario['store_id'],
        'provider_id' => null,
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'prefix' => 'serie-002',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 999999,
        'valid_from' => null,
        'valid_to' => null,
        'credential_ref' => null,
        'control_identifier' => null,
        'sequence_length' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * La SERIE del CONTROL del perfil propio, en la tienda del escenario.
 *
 * @param  array<string, int>  $escenario
 */
function acuerdo_serie_control(array $escenario, int $perfilId): int
{
    return DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $perfilId,
        'store_id' => $escenario['store_id'],
        'provider_id' => null,
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'prefix' => null,
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 99999999,
        'valid_from' => null,
        'valid_to' => null,
        'credential_ref' => null,
        'control_identifier' => '00',
        'sequence_length' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * El PEDIDO de la venta: dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function acuerdo_pedido(array $escenario): array
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
                'amount' => '55.0000',
            ],
        ],
    ];
}

/**
 * 1.13 — la serie que reserva el cobro y la que emite el comprobante son la
 * MISMA, con una tienda de perfil propio.
 *
 * REGLA que fija: *la venta y su comprobante son un solo hecho, y el
 * comprobante se emite con la serie que reservo la venta*. La serie sale de
 * la reserva, y la reserva sale del perfil de la tienda: las dos son la
 * misma serie por construccion, y la fila lo deja ver.
 */
test('test_la_serie_que_reserva_el_cobro_es_la_misma_que_emite_el_documento', function () {
    config(['fiscal.emission_enabled' => true]);

    // El producto se grava con la CATEGORIA DEL PERFIL PROPIO: el 10 %.
    $categoriaId = (int) DB::table('tax_categories')
        ->where('fiscal_profile_id', $this->perfilPropioId)
        ->where('code', 'propia')
        ->value('id');

    DB::table('products')->where('id', $this->escenario['product_id'])->update([
        'tax_category_id' => $categoriaId,
        'updated_at' => now(),
    ]);

    $serieDocumento = acuerdo_serie_documento($this->escenario, $this->perfilPropioId);
    $serieControl = acuerdo_serie_control($this->escenario, $this->perfilPropioId);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', acuerdo_pedido($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('fiscal.status', 'issued');

    $saleId = (int) $respuesta->json('sale_id');

    // 1. La RESERVA del documento es de la serie del perfil PROPIO.
    $reserva = DB::table('fiscal_document_reservations')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->where('series_role', 'document')
        ->orderBy('id')
        ->first();

    expect($reserva)->not->toBeNull()
        ->and((int) $reserva->series_id)->toBe($serieDocumento);

    // 2. Y el COMPROBANTE emitido usa esa MISMA serie y ese MISMO perfil.
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento)->not->toBeNull()
        ->and((int) $documento->series_id)->toBe($serieDocumento)
        ->and((int) $documento->fiscal_profile_id)->toBe($this->perfilPropioId)
        ->and((int) $documento->store_id)->toBe($this->escenario['store_id']);

    // 3. Y el CONTROL es de la serie de control del MISMO perfil.
    $reservaControl = DB::table('fiscal_document_reservations')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->where('series_role', 'control')
        ->orderBy('id')
        ->first();

    expect($reservaControl)->not->toBeNull()
        ->and((int) $reservaControl->series_id)->toBe($serieControl);

    // 4. Y la serie del perfil DEL PAIS no se ha tocado: la venta no ha
    //    pasado por la serie de otro regimen.
    expect((int) DB::table('fiscal_series')
        ->where('id', $this->escenario['document_series_id'])
        ->value('next_number'))->toBe(1)
        ->and((int) DB::table('fiscal_series')
            ->where('id', $this->escenario['control_series_id'])
            ->value('next_number'))->toBe(1);

    // 5. Y el impuesto sale del perfil propio: el 10 %, no el 16 % del otro.
    expect((string) DB::table('sale_totals')->where('sale_id', $saleId)->value('tax_total'))
        ->toBe('5.0000');
});
