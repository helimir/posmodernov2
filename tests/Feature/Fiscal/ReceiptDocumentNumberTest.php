<?php

use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El NUMERO del comprobante fiscal.
 *
 * REGLA que fija este test: *el comprobante lleva el numero COMPLETO del
 * documento, con el prefijo de su serie*.
 *
 * Hay cinco series en el catalogo, y sus prefijos son distintos por pais
 * (`serie-001`, `33`, `NMX`, `CFE`, `FE`). Un numero **crudo** no identifica
 * un documento: "Nº 3" podria ser la factura 3 de cualquiera de ellas. Y el
 * comprobante **tiene que coincidir con lo que dice la venta**: el papel y la
 * respuesta del cobro son el mismo documento.
 */
test('el_comprobante_lleva_el_numero_completo_del_documento_con_el_prefijo_de_su_serie', function () {
    $escenario = PosFixture::escenario();

    // La serie de documento del perfil del escenario: tiene prefijo, como
    // todas las de verdad.
    $serie = DB::table('fiscal_series')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('series_role', 'document')
        ->first();

    expect($serie)->not->toBeNull()
        ->and($serie->prefix)->not->toBeNull()
        ->and($serie->prefix)->not->toBe('');

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $escenario['profile_id'],
        'store_id' => $escenario['store_id'],
        'series_id' => $serie->id,
        'document_type' => 'invoice',
        'status' => 'issued',
        'number' => 3,
        'issue_date' => '2026-11-12',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 16,
        'total_amount' => 116,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $documentId);
    $html = (new ReceiptRenderer)->render($template);

    // El numero del comprobante es el COMPLETO: prefijo + secuencial. Y es
    // EXACTAMENTE como lo compone la venta (`invoice_no`), para que el papel y
    // la respuesta del cobro sean el mismo documento.
    $completo = trim($serie->prefix).'3';

    expect($template->documentNumber)->toBe($completo)
        // El renderer escapa el ordinal: "Nº" sale como "N&#186;".
        ->and($html)->toContain('N&#186; '.$completo);

    DB::table('fiscal_documents')->where('id', $documentId)->delete();
});

/**
 * Y un documento SIN serie sigue llevando su numero: no se inventa un prefijo.
 */
test('un_documento_sin_serie_lleva_su_numero_tal_cual', function () {
    $escenario = PosFixture::escenario();

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $escenario['profile_id'],
        'store_id' => $escenario['store_id'],
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

    $template = ReceiptTemplate::fromDocument(DB::connection(), $documentId);

    expect($template->documentNumber)->toBe('42');

    DB::table('fiscal_documents')->where('id', $documentId)->delete();
});
