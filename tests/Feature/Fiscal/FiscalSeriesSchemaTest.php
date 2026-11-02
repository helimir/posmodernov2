<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.1-T — hay DOS numeraciones con autoridades distintas.
 *
 * Regla que fija: la serie se distingue por su ROL y por la AUTORIDAD que
 * asigna el número. `series_kind` queda como alias documentado y NO es una
 * columna (design §F.2, conflicto C2).
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_serie_se_distingue_por_rol_y_por_autoridad_y_no_por_un_alias_de_columna', function () {
    $columns = Schema::getColumnListing('fiscal_series');

    // El rol canónico y la autoridad son columnas; `series_kind` no existe.
    expect($columns)->toContain('series_role')
        ->and($columns)->toContain('assigned_by')
        ->and($columns)->not->toContain('series_kind')
        ->and($columns)->toContain('prefix')
        ->and($columns)->toContain('next_number')
        ->and($columns)->toContain('from_number')
        ->and($columns)->toContain('to_number')
        ->and($columns)->toContain('valid_from')
        ->and($columns)->toContain('valid_to')
        ->and($columns)->toContain('credential_ref')
        ->and($columns)->toContain('control_identifier')
        ->and($columns)->toContain('sequence_length');

    // La traza de reservas: quién tomó cada número y cuándo.
    expect(Schema::getColumnListing('fiscal_document_reservations'))
        ->toContain('series_id')
        ->toContain('number')
        ->toContain('series_role')
        ->toContain('reserved_by')
        ->toContain('reserved_at')
        ->toContain('voided_at');

    // `fiscal_providers` la crea M02 (PR4, ya fusionado): M21 sólo la
    // referencia, no la vuelve a crear.
    expect(Schema::hasTable('fiscal_providers'))->toBeTrue()
        ->and(FiscalSeriesFixture::migrationsCreating('fiscal_providers'))
        ->toBe(['2026_11_01_000002_create_fiscal_profiles_table.php']);

    $context = FiscalSeriesFixture::context();

    // Las DOS numeraciones: la del documento (del emisor) y el Número de
    // Control (de la imprenta digital). Coexisten y se distinguen por rol y
    // por autoridad.
    $document = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'prefix' => 'DOC',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 999,
    ]);

    $control = FiscalSeriesFixture::series($context, [
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'prefix' => 'CTL',
        'next_number' => 1,
        'from_number' => 5000,
        'to_number' => 6000,
        'credential_ref' => 'ZZ_PRINTER_CREDENTIAL',
        'control_identifier' => '00',
        'sequence_length' => 8,
    ]);

    $series = DB::table('fiscal_series')->whereIn('id', [$document, $control])->get();

    expect($series)->toHaveCount(2)
        ->and($series->pluck('series_role')->sort()->values()->all())->toBe(['control', 'document'])
        ->and($series->pluck('assigned_by')->sort()->values()->all())->toBe(['issuer', 'printer']);

    // La serie de control es el ESPEJO LOCAL del rango autorizado de la
    // imprenta (C3): guarda el rango y la referencia de su credencial.
    $controlRow = $series->firstWhere('id', $control);

    expect((int) $controlRow->from_number)->toBe(5000)
        ->and((int) $controlRow->to_number)->toBe(6000)
        ->and($controlRow->credential_ref)->toBe('ZZ_PRINTER_CREDENTIAL')
        ->and($controlRow->control_identifier)->toBe('00')
        ->and((int) $controlRow->sequence_length)->toBe(8);

    // Los conjuntos canónicos completos: 3 roles × 4 autoridades.
    foreach (['document', 'control', 'retention_receipt'] as $role) {
        foreach (['issuer', 'authority', 'printer', 'psp'] as $authority) {
            FiscalSeriesFixture::series($context, [
                'series_role' => $role,
                'assigned_by' => $authority,
            ]);
        }
    }

    expect(DB::table('fiscal_series')->count())->toBe(14);

    // Y un rol o una autoridad que no son canónicos NO entran: el conjunto
    // cerrado lo hace cumplir el esquema, no una convención del código.
    expect(fn () => DB::table('fiscal_series')->insert([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_role' => 'document_number',
        'assigned_by' => 'issuer',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('fiscal_series')->insert([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_role' => 'document',
        'assigned_by' => 'imprenta',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
