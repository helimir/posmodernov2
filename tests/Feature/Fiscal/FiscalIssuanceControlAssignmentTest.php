<?php

use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FakeInvoiceProvider;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.6.6 — la asignacion de control es UNICA y no se reasigna.
 *
 * Regla que fija: *el numero de control ya publicado no se devuelve ni se
 * reasigna*. `fiscal_document_control_assignments` tiene UNIQUE en
 * `fiscal_document_id`: una reemision sobre el MISMO documento no puede
 * insertar una segunda fila, porque el control ya esta en manos de la
 * autoridad y devolverlo entregaria DOS controles para un comprobante.
 *
 * Este test llama al `FiscalIssuanceService` DOS VECES sobre el mismo
 * documento. Es el UNICO camino que llega al fix de leer-antes-de-insertar:
 * el coordinador (`FiscalIssuanceCoordinator`) sale antes, porque su guarda
 * no vuelve a llamar al proveedor si el documento ya esta `issued`.
 *
 * El test NO envuelve el caso en la transaccion de `RefreshDatabase`: las
 * dos emisiones escriben de verdad, y la segunda tiene que ver la fila que
 * dejo la primera.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

/**
 * El borrador que se entrega al actor intermedio.
 */
function borradorDeControl(): FiscalDocumentDraft
{
    return new FiscalDocumentDraft(
        documentType: 'invoice',
        seriesPrefix: 'ZZ',
        documentNumber: 42,
        issuedAt: new DateTimeImmutable('2026-11-02 10:00:00'),
        currencyCode: 'ZZA',
    );
}

/**
 * 0.6.6 — una reemision sobre el mismo documento sobrevive y NO duplica la
 * asignacion de control.
 */
test('test_una_reemision_sobre_el_mismo_documento_no_duplica_la_asignacion_de_control', function () {
    $context = FiscalSeriesFixture::context();

    // El documento YA EXISTE: el `FiscalIssuanceService` no lo crea, hace
    // UPDATE sobre un id que ya esta.
    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'document_type' => 'invoice',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $provider = new FakeInvoiceProvider;
    $service = new FiscalIssuanceService(DB::connection(), $provider);
    $draft = borradorDeControl();

    // 1. La PRIMERA emision: publica el control `00-00000001`.
    $provider->controlNumber = '00-00000001';

    $service->issue($documentId, $draft, true);

    expect(DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->count())->toBe(1);

    // 2. La SEGUNDA emision, sobre el MISMO documento, y con el actor
    //    devolviendo OTRO control: es lo que pasaria si una reemision
    //    llegara con una impresora distinta.
    //
    //    NO puede reventar: sin leer la fila existente, el INSERT violaria
    //    la UNIQUE de `fiscal_document_id`.
    $provider->controlNumber = '00-00000002';

    $fallo = null;

    try {
        $service->issue($documentId, $draft, true);
    } catch (Throwable $excepcion) {
        $fallo = $excepcion;
    }

    expect($fallo)->toBeNull();

    // 3. Queda UNA sola asignacion, y su control es el de la PRIMERA
    //    emision: el control publicado NO se reasigna.
    $asignaciones = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->get();

    expect($asignaciones)->toHaveCount(1)
        ->and($asignaciones->first()->control_number)->toBe('00-00000001');

    // 4. Y el documento sigue emitido.
    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))
        ->toBe('issued');

    // 5. Las DOS emisiones quedan auditadas, y la segunda registra la
    //    DISCREPANCIA: el actor devolvio un control distinto del publicado.
    $eventos = DB::table('fiscal_document_events')
        ->where('fiscal_document_id', $documentId)
        ->orderBy('id')
        ->get();

    expect($eventos)->toHaveCount(2);

    $primero = json_decode((string) $eventos[0]->payload, true);
    $segundo = json_decode((string) $eventos[1]->payload, true);

    expect($primero)->not->toHaveKey('discrepancy')
        ->and($segundo['discrepancy'])->toBe('control_distinto_al_publicado')
        ->and($segundo['control_number'])->toBe('00-00000002');
});
