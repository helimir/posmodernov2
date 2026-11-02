<?php

use App\Domain\Fiscal\ContingencyService;
use App\Domain\Fiscal\Enums\ContingencyCause;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.9-T — el papel de contingencia se concilia en PDF al día siguiente.
 *
 * Regla que fija: *el papel sólo es contingencia y se concilia a D+1*. El papel
 * no compite con el documento digital: abrir un talonario no emite ningún
 * documento fiscal ni consume ningún correlativo.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_el_papel_de_contingencia_se_concilia_en_pdf_al_dia_siguiente', function () {
    $context = FiscalSeriesFixture::context();

    $service = new ContingencyService(DB::connection());

    $formId = $service->open(
        $context['store_id'],
        $context['profile_id'],
        ContingencyCause::PowerFailure,
        1,
        500,
        1,
        100,
        new DateTimeImmutable('2026-11-02 08:00:00'),
    );

    // 1. El papel NO es un documento paralelo: abrir el talonario no emite
    //    ningun documento fiscal ni consume ningun correlativo.
    expect(DB::table('fiscal_documents')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0);

    $form = DB::table('fiscal_contingency_forms')->where('id', $formId)->first();

    expect((int) $form->control_from)->toBe(1)
        ->and((int) $form->control_to)->toBe(500)
        ->and((int) $form->document_from)->toBe(1)
        ->and((int) $form->document_to)->toBe(100);

    // 2. Sin registrar los documentos fisicos NO se puede remitir.
    expect(fn () => $service->remitPdfs($formId, new DateTimeImmutable('2026-11-03 09:00:00')))
        ->toThrow(RuntimeException::class);

    $service->register($formId, new DateTimeImmutable('2026-11-02 18:00:00'));

    // 3. Y la remision va AL DIA SIGUIENTE: el mismo dia no vale.
    expect(fn () => $service->remitPdfs($formId, new DateTimeImmutable('2026-11-02 20:00:00')))
        ->toThrow(RuntimeException::class);

    expect(DB::table('fiscal_contingency_forms')->where('id', $formId)->value('pdf_remitted_at'))->toBeNull();

    // 4. Al dia siguiente, si.
    $service->remitPdfs($formId, new DateTimeImmutable('2026-11-03 09:00:00'));

    $form = DB::table('fiscal_contingency_forms')->where('id', $formId)->first();

    expect($form->registered_at)->not->toBeNull()
        ->and($form->pdf_remitted_at)->not->toBeNull();

    // 5. Y la causa queda tasada: el conjunto es cerrado.
    expect(ContingencyCause::cases())->toHaveCount(8)
        ->and(array_map(fn (ContingencyCause $c) => $c->value, ContingencyCause::cases()))
        ->toContain('power_failure');
});
