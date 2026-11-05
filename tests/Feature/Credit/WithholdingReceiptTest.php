<?php

use App\Domain\Fiscal\DerivedDocumentService;
use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Domain\Fiscal\Enums\TaxType;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\ValueObjects\RetentionReceiptDraft;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxContext;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;
use App\Models\Customer;
use App\Models\Product;
use App\Models\TaxCategory;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.6.4 / 0.6.4-T — el IVA retenido es un documento PROPIO.
 *
 * Regla que fija: *el IVA retenido es credito del comprador, con documento
 * propio*. El retenido NO es una categoria de linea de la venta: sale en su
 * PROPIO comprobante, con su serie, su numeracion de CATORCE caracteres y su
 * nodo de impuesto de CABECERA. La venta conserva sus nodos de impuesto
 * TRASLADADO, y el IVA retenido no aparece en ninguno de ellos.
 */

/**
 * El escenario: una venta emitida con su desglose de impuestos, y la serie
 * del comprobante de retencion.
 *
 * @return array<string, int>
 */
function escenarioRetencion(): array
{
    $context = FiscalSeriesFixture::context();

    // La serie del DOCUMENTO de venta.
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    // La serie del COMPROBANTE DE RETENCION: es OTRA serie, con su propio
    // correlativo.
    $retentionSeriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'retention_receipt',
        'assigned_by' => 'issuer',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 99999999,
    ]);

    $category = TaxCategory::create([
        'fiscal_profile_id' => $context['profile_id'],
        'code' => 'gravable',
        'name' => 'Gravable',
        'evidence_status' => 'verified',
        'source_ref' => 'REF-CATEGORIA',
    ]);

    $taxRateId = DB::table('tax_rates')->insertGetId([
        'tax_category_id' => $category->id,
        'percentage' => 16,
        'rounding_rule' => 'line',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'verified',
        'source_ref' => 'REF-TASA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = Customer::create(['name' => 'Comprador de la retencion']);

    $product = Product::create([
        'code' => 'ZZP-RETENCION-1',
        'name' => 'Producto de la retencion',
        'type' => 'product',
    ]);

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $context['store_id'],
        'customer_id' => $customer->id,
        'invoice_no' => 'ZZ1',
        'sale_date' => '2026-11-04',
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $itemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $product->id,
        'store_id' => $context['store_id'],
        'quantity' => '1.0000',
        'unit_price' => '100.0000',
        'discount' => '0.0000',
        'tax_amount' => '16.0000',
        'base_amount' => '100.0000',
        'line_total' => '116.0000',
        'evidence_status' => 'verified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // El COMPROBANTE de la venta: el documento sobre el que se retiene.
    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_id' => $seriesId,
        'number' => 1,
        'document_type' => 'invoice',
        'status' => 'issued',
        'customer_id' => $customer->id,
        'subtotal' => '100.0000',
        'tax_total' => '16.0000',
        'total_amount' => '116.0000',
        'issue_date' => '2026-11-04',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sales')->where('id', $saleId)->update(['fiscal_document_id' => $documentId]);

    return [
        'store_id' => $context['store_id'],
        'customer_id' => (int) $customer->id,
        'category_id' => (int) $category->id,
        'tax_rate_id' => $taxRateId,
        'item_id' => $itemId,
        'document_id' => $documentId,
        'retention_series_id' => $retentionSeriesId,
    ];
}

/**
 * El motor de impuestos con el contexto de la prueba.
 */
function motorDePrueba(): TaxCalculator
{
    return new TaxCalculator(new TaxContext(
        roundingRule: RoundingRule::Line,
        taxInclusiveDefault: false,
        currencyDecimals: 2,
        exemptionMarker: '(E)',
        formalContributorPhrase: 'Contribuyentes Formales del IVA',
        seriesPrefixRequiredToken: 'serie',
    ));
}

/**
 * 0.6.4-T — el IVA retenido sale en su PROPIO documento, no como linea de la
 * venta.
 */
test('test_el_iva_retenido_se_emite_como_documento_propio_y_no_como_linea_de_la_venta', function () {
    $escenario = escenarioRetencion();
    $emitido = new DateTimeImmutable('2026-11-05 10:00:00');

    $calculator = motorDePrueba();

    // 1. EL MOTOR. El desglose de la VENTA: el impuesto que se TRASLADA al
    //    comprador. Y el desglose del RETENIDO: el credito del comprador, que
    //    sale en otro documento.
    $venta = $calculator->calculate(new TaxDocumentInput(
        lines: [
            new TaxLineInput(
                taxCategoryId: $escenario['category_id'],
                taxRateId: $escenario['tax_rate_id'],
                taxRateSnapshot: '16.0000',
                evidenceStatus: 'verified',
                method: TaxMethod::Exclusive,
                unitPrice: '100.0000',
                quantity: '1.0000',
            ),
        ],
        documentDiscount: '0.0000',
        issuedAt: $emitido,
    ));

    $retencion = $calculator->calculate(new TaxDocumentInput(
        lines: [
            new TaxLineInput(
                taxCategoryId: $escenario['category_id'],
                taxRateId: $escenario['tax_rate_id'],
                taxRateSnapshot: '16.0000',
                evidenceStatus: 'verified',
                method: TaxMethod::Exclusive,
                unitPrice: '100.0000',
                quantity: '1.0000',
                taxType: TaxType::Withholding,
            ),
        ],
        documentDiscount: '0.0000',
        issuedAt: $emitido,
    ));

    // El motor NO MEZCLA los dos impuestos: el de la venta es trasladado y
    // el de la retencion es retenido.
    expect($venta->lines[0]['tax_type'])->toBe('transfer')
        ->and($retencion->lines[0]['tax_type'])->toBe('withholding');

    // 2. El desglose de la VENTA, escrito con lo que dice el motor: una fila
    //    por linea y una de cabecera, las dos de impuesto TRASLADADO.
    DB::table('fiscal_document_taxes')->insert([
        [
            'fiscal_document_id' => $escenario['document_id'],
            'line_id' => $escenario['item_id'],
            'tax_type' => $venta->lines[0]['tax_type'],
            'tax_category_id' => $escenario['category_id'],
            'tax_rate_id' => $escenario['tax_rate_id'],
            'tax_rate_snapshot' => '16.0000',
            'evidence_status' => 'verified',
            'base_amount' => $venta->lines[0]['base_amount'],
            'tax_amount' => $venta->lines[0]['tax_amount'],
            'rounding_adjustment' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'fiscal_document_id' => $escenario['document_id'],
            'line_id' => null,
            'tax_type' => $venta->lines[0]['tax_type'],
            'tax_category_id' => null,
            'tax_rate_id' => null,
            'tax_rate_snapshot' => null,
            'evidence_status' => null,
            'base_amount' => '0.0000',
            'tax_amount' => $venta->taxTotal,
            'rounding_adjustment' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // 3. El COMPROBANTE DE RETENCION: un documento PROPIO, con su serie y su
    //    numeracion de CATORCE caracteres (AAAAMM + ocho digitos).
    $service = new DerivedDocumentService(DB::connection(), new NumberSeries(DB::connection()));

    $receiptId = $service->issueRetentionReceipt(
        $escenario['retention_series_id'],
        new RetentionReceiptDraft(
            sourceDocumentId: $escenario['document_id'],
            periodFrom: '2026-11-01',
            periodTo: '2026-11-30',
            baseAmount: $retencion->lines[0]['base_amount'],
            taxCaused: $retencion->lines[0]['tax_amount'],
            taxRetained: $retencion->lines[0]['tax_amount'],
        ),
        $emitido,
    );

    $receipt = DB::table('fiscal_documents')->where('id', $receiptId)->first();

    expect($receipt)->not->toBeNull()
        ->and($receipt->document_type)->toBe('retention_receipt')
        ->and((int) $receipt->series_id)->toBe($escenario['retention_series_id'])
        ->and(strlen((string) $receipt->number))->toBe(14)
        ->and((string) $receipt->number)->toBe('20261100000001')
        ->and((string) $receipt->period_from)->toBe('2026-11-01')
        ->and((string) $receipt->period_to)->toBe('2026-11-30');

    // 4. El IVA retenido es un nodo de CABECERA de SU documento: `line_id` en
    //    `null` y `tax_type='withholding'`, con su base y su monto.
    $impuestos = DB::table('fiscal_document_taxes')->where('fiscal_document_id', $receiptId)->get();

    expect($impuestos)->toHaveCount(1);

    $nodo = $impuestos->first();

    expect($nodo->line_id)->toBeNull()
        ->and($nodo->tax_type)->toBe('withholding')
        ->and((string) $nodo->base_amount)->toBe('100.0000')
        ->and((string) $nodo->tax_amount)->toBe('16.0000');

    // 5. Y la VENTA conserva SUS nodos: TODOS de impuesto TRASLADADO. El IVA
    //    retenido NO es una linea de la venta.
    $deLaVenta = DB::table('fiscal_document_taxes')
        ->where('fiscal_document_id', $escenario['document_id'])
        ->get();

    expect($deLaVenta)->toHaveCount(2)
        ->and($deLaVenta->where('tax_type', 'withholding')->count())->toBe(0)
        ->and($deLaVenta->pluck('tax_type')->unique()->values()->all())->toBe(['transfer']);

    // 6. Son DOS documentos distintos: el de la venta y el de la retencion.
    expect((int) $receiptId)->not->toBe($escenario['document_id'])
        ->and(DB::table('fiscal_documents')->count())->toBe(2);
});

/**
 * 0.6.4 — el motor de impuestos DISTINGUE el impuesto trasladado del
 * retenido.
 *
 * Regla que fija: *el tipo del impuesto lo declara la linea*. Una linea de
 * retenido produce `tax_type='withholding'` y una linea normal produce
 * `tax_type='transfer'`: el motor no los MEZCLA, y el IVA retenido no acaba
 * como impuesto trasladado de una venta.
 */
test('test_el_motor_de_impuestos_distingue_el_impuesto_trasladado_del_retenido', function () {
    $result = motorDePrueba()->calculate(new TaxDocumentInput(
        lines: [
            // La linea NORMAL: el impuesto que se traslada al comprador.
            new TaxLineInput(
                taxCategoryId: 1,
                taxRateId: 7,
                taxRateSnapshot: '16.0000',
                evidenceStatus: 'verified',
                method: TaxMethod::Exclusive,
                unitPrice: '100.0000',
                quantity: '1.0000',
            ),
            // La linea de RETENIDO: el credito del comprador, en su propio
            // documento.
            new TaxLineInput(
                taxCategoryId: 1,
                taxRateId: 7,
                taxRateSnapshot: '16.0000',
                evidenceStatus: 'verified',
                method: TaxMethod::Exclusive,
                unitPrice: '100.0000',
                quantity: '1.0000',
                taxType: TaxType::Withholding,
            ),
        ],
        documentDiscount: '0.0000',
        issuedAt: new DateTimeImmutable('2026-11-05 10:00:00'),
    ));

    expect($result->lines[0]['tax_type'])->toBe('transfer')
        ->and($result->lines[1]['tax_type'])->toBe('withholding')
        ->and(TaxType::Transfer->value)->toBe('transfer')
        ->and(TaxType::Withholding->value)->toBe('withholding');
});
