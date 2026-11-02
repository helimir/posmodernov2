<?php

use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.6-T — el comprobante renderiza base e IVA por alícuota con el marcador
 * de exento.
 *
 * Regla que fija: *el art. 8 es obligatorio*. El contenido del comprobante es
 * normado: denominación, fecha, hora, las partes con su identificativo, la
 * base imponible y el impuesto discriminados por alícuota, el valor total, el
 * marcador de exento y la frase del contribuyente formal. Los tokens salen del
 * perfil, nunca del código.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_el_comprobante_renderiza_base_e_iva_por_alicuota_con_el_marcador_exento', function () {
    $context = FiscalSeriesFixture::context();

    // Los tokens NORMATIVOS los declara el perfil, no el codigo.
    foreach ([
        'exemption_marker' => '(E)',
        'formal_contributor_phrase' => 'Contribuyentes Formales del IVA',
        'provision_reference' => 'Providencia SNAT/2026/00093',
    ] as $key => $value) {
        DB::table('fiscal_profile_fields')->insert([
            'fiscal_profile_id' => $context['profile_id'],
            'key' => $key,
            'data_type' => 'string',
            'label' => $key,
            'value' => $value,
            'required' => false,
            'applies_to' => 'document',
            'evidence_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'document_type' => 'invoice',
        'status' => 'issued',
        'number' => 42,
        'issue_date' => '2026-10-15',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 16,
        'total_amount' => 116,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // El emisor con su RIF; el receptor, persona natural SIN RIF: lleva el
    // documento de identidad, que es lo que la norma admite.
    DB::table('fiscal_document_parties')->insert([
        [
            'fiscal_document_id' => $documentId,
            'role' => 'issuer',
            'name' => 'Comercial del Norte',
            'fiscal_id' => 'J-31234567-8',
            'fiscal_id_kind' => 'rif',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'fiscal_document_id' => $documentId,
            'role' => 'receiver',
            'name' => 'Maria Perez',
            'fiscal_id' => null,
            'fiscal_id_kind' => 'cedula',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // DOS alicuotas: la gravada y la exenta.
    DB::table('fiscal_document_taxes')->insert([
        [
            'fiscal_document_id' => $documentId,
            'line_id' => null,
            'tax_type' => 'transfer',
            'tax_rate_snapshot' => 16,
            'base_amount' => 100,
            'tax_amount' => 16,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'fiscal_document_id' => $documentId,
            'line_id' => null,
            'tax_type' => 'transfer',
            'tax_rate_snapshot' => 0,
            'base_amount' => 50,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('fiscal_document_control_assignments')->insert([
        'fiscal_document_id' => $documentId,
        'control_number' => '00-00000042',
        'control_range_from' => 1,
        'control_range_to' => 9999,
        'control_assigned_at' => now(),
        'printer_tax_id' => 'J-31234567-8',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $documentId);
    $html = (new ReceiptRenderer)->render($template);

    // 1. La denominacion, la fecha DDMMAAAA y la hora HH.MM.SS con a.m/p.m.
    expect($html)->toContain('invoice')
        ->and($html)->toContain('15/10/2026')
        ->and($html)->toMatch('/\d{2}\.\d{2}\.\d{2} (a\.m|p\.m)/');

    // 2. Las partes con su identificativo: el emisor con su RIF, el receptor
    //    con su documento de identidad.
    expect($html)->toContain('Comercial del Norte')
        ->and($html)->toContain('J-31234567-8')
        ->and($html)->toContain('Maria Perez')
        ->and($html)->toContain('cedula');

    // 3. La base imponible y el IVA, DISCRIMINADOS por alicuota.
    expect($html)->toContain('Base: 100.0000')
        ->and($html)->toContain('16%')
        ->and($html)->toContain('16.0000')
        ->and($html)->toContain('Base: 50.0000');

    // 4. El valor total.
    expect($html)->toContain('Total: 116.0000');

    // 5. El marcador de exento, junto a la alicuota cero.
    expect($html)->toContain('(E)');

    // 6. Y la frase del contribuyente formal, porque hay operacion exenta.
    expect($html)->toContain('Contribuyentes Formales del IVA');

    // 7. El numero de documento y el de control: los mismos en todas las
    //    paginas.
    expect($html)->toContain('N&#186; 42')
        ->and($html)->toContain('N&#186; Control 00-00000042');

    // 8. La tipografia: la imprenta al menos a 6 puntos, y la identificacion
    //    del emisor y el control al menos a 8.
    expect($html)->toContain('font-size:8pt')
        ->and($html)->toContain('font-size:6pt');

    // 9. Y la conformidad con la providencia.
    expect($html)->toContain('Emitido conforme a');

    // 10. Los tokens vienen del PERFIL: el renderer no lleva el literal.
    expect(file_get_contents(app_path('Domain/Fiscal/ReceiptRenderer.php')))
        ->not->toContain('(E)')
        ->not->toContain('Contribuyentes Formales del IVA');
});
