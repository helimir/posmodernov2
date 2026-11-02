<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.2-T — la auditoría fiscal es APPEND-ONLY.
 *
 * Regla que fija: toda acción de emisión, modificación o anulación queda
 * auditada, y esa traza **no se actualiza ni se borra jamás**. El bloqueo es
 * de la base de datos (trigger), no de la aplicación: una sentencia `UPDATE`
 * o `DELETE` sobre la traza aborta.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_los_eventos_fiscales_son_append_only_y_no_admiten_borrado', function () {
    // 1. La traza NO es un registro con `updated_at`: no se reescribe.
    $columns = Schema::getColumnListing('fiscal_document_events');

    expect($columns)->toContain('fiscal_document_id')
        ->and($columns)->toContain('action')
        ->and($columns)->toContain('actor')
        ->and($columns)->toContain('occurred_at')
        ->and($columns)->toContain('payload')
        ->and($columns)->toContain('created_at')
        ->and($columns)->not->toContain('updated_at')
        ->and($columns)->not->toContain('deleted_at');

    $context = FiscalSeriesFixture::context();

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'document_type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-11-01',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Se puede AÑADIR: eso es lo único que se permite.
    $eventId = DB::table('fiscal_document_events')->insertGetId([
        'fiscal_document_id' => $documentId,
        'action' => 'issue',
        'actor' => null,
        'occurred_at' => now(),
        'payload' => json_encode(['authority_reference' => 'CUFE-0001']),
        'created_at' => now(),
    ]);

    expect(DB::table('fiscal_document_events')->count())->toBe(1);

    // 3. Un UPDATE aborta: la traza no se reescribe.
    try {
        DB::table('fiscal_document_events')->where('id', $eventId)->update([
            'payload' => json_encode(['tampered' => true]),
        ]);

        $this->fail('El UPDATE de un evento fiscal deberia abortar.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('append-only');
    }

    // 4. Un DELETE aborta: la traza no se borra.
    try {
        DB::table('fiscal_document_events')->where('id', $eventId)->delete();

        $this->fail('El DELETE de un evento fiscal deberia abortar.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('append-only');
    }

    // 5. El evento de emisión sigue intacto, con su carga útil original.
    $event = DB::table('fiscal_document_events')->where('id', $eventId)->first();

    expect($event)->not->toBeNull()
        ->and($event->action)->toBe('issue')
        ->and((int) $event->fiscal_document_id)->toBe((int) $documentId)
        ->and(json_decode((string) $event->payload, true))->toBe(['authority_reference' => 'CUFE-0001']);

    // 6. Y el control tiene UNA SEDE: no se duplica en la cabecera.
    expect(Schema::getColumnListing('fiscal_documents'))->not->toContain('control_number')
        ->and(Schema::getColumnListing('fiscal_document_control_assignments'))->toContain('control_number');
});
