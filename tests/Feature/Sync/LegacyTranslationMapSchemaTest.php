<?php

use App\Models\LegacyTranslationMap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.8.2-T — el MAPA DE TRADUCCION es DATO, no codigo.
 *
 * Regla que fija: *el mapa de traducción es dato auditable, no código*.
 *
 * El terminal legacy empuja SQL crudo de MySQL con los nombres de SU
 * esquema (`selling_info`, `selling_item`, `customer_transactions`, ...).
 * El maestro tiene PostgreSQL con otro esquema, asi que los IDENTIFICADORES
 * se traducen: y esa traduccion vive en una TABLA sembrada
 * (`legacy_translation_map`), no en un `if` por tabla.
 *
 * La DIRECCION es legacy -> destino: el mapa dice "esta columna del legacy
 * es ESTA columna de aqui". Y `new_table IS NULL` significa "esta columna
 * NO se traslada" (el dato se deriva o no existe en el destino).
 *
 * La siembra del mapa completo es de la unidad 8-iv-b; aqui la siembra la
 * hace el propio test, y lo que se fija es la REGLA: el mapa es una fila
 * auditable y la resolucion sale de la fila, no del codigo.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): en
 * PostgreSQL un error dentro de una transaccion la ABORTA entera, y aqui hace
 * falta que la restriccion unica rechazada no se lleve por delante el resto
 * del test.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::table('legacy_translation_map')->delete();
    DB::table('sync_batches')->delete();
});

test('test_la_traduccion_resuelve_tabla_y_columnas_contra_el_mapa_sembrado', function () {
    // 1. La tabla existe con sus CINCO columnas del mapa.
    expect(Schema::hasTable('legacy_translation_map'))->toBeTrue()
        ->and(Schema::hasColumns('legacy_translation_map', [
            'legacy_table',
            'legacy_column',
            'new_table',
            'new_column',
            'transform',
        ]))->toBeTrue();

    // 2. La DIRECCION es legacy -> destino. El nombre del legacy NO existe
    //    en el esquema destino, y el nombre del destino SI.
    LegacyTranslationMap::create([
        'legacy_table' => 'selling_info',
        'legacy_column' => 'invoice_number',
        'new_table' => 'sales',
        'new_column' => 'invoice_number',
        'transform' => 'rename',
    ]);

    $resuelta = LegacyTranslationMap::query()
        ->where('legacy_table', 'selling_info')
        ->where('legacy_column', 'invoice_number')
        ->first();

    expect($resuelta)->not->toBeNull()
        ->and($resuelta->new_table)->toBe('sales')
        ->and($resuelta->new_column)->toBe('invoice_number')
        ->and($resuelta->transform)->toBe('rename');

    // La direccion se comprueba contra el ESQUEMA, no contra un comentario:
    // `selling_info` es del legacy (aqui no existe) y `sales` es del
    // destino (aqui si).
    expect(Schema::hasTable('selling_info'))->toBeFalse()
        ->and(Schema::hasTable('sales'))->toBeTrue();

    // 3. Una columna que NO se traslada: `new_table` es NULL.
    $descartada = LegacyTranslationMap::create([
        'legacy_table' => 'selling_info',
        'legacy_column' => 'total',
        'new_table' => null,
        'new_column' => null,
        'transform' => null,
    ]);

    expect($descartada->new_table)->toBeNull()
        ->and($descartada->new_column)->toBeNull()
        ->and($descartada->exists)->toBeTrue();

    $leida = LegacyTranslationMap::query()
        ->where('legacy_table', 'selling_info')
        ->where('legacy_column', 'total')
        ->first();

    expect($leida)->not->toBeNull()
        ->and($leida->new_table)->toBeNull();

    // 4. El mapa esta SEMBRADO: hay filas, y sus nombres son TECNICOS.
    //    Un `if ($tabla === 'selling_info')` en el codigo seria un defecto:
    //    el mapa es lo que dice que `selling_info` es `sales`.
    $mapa = LegacyTranslationMap::query()->get();

    expect($mapa->count())->toBeGreaterThan(0);

    // El CATALOGO es la fuente de la regla: ningun identificador puede ser
    // un codigo ISO, un nombre de pais ni un codigo de moneda.
    $codigosIso = DB::table('countries')->pluck('iso_code')
        ->map(fn (mixed $codigo): string => strtolower(trim((string) $codigo)))
        ->filter(fn (string $codigo): bool => $codigo !== '')
        ->all();

    $nombresPais = DB::table('countries')->pluck('name')
        ->map(fn (mixed $nombre): string => strtolower(trim((string) $nombre)))
        ->filter(fn (string $nombre): bool => $nombre !== '')
        ->all();

    $codigosMoneda = DB::table('currencies')->pluck('code')
        ->map(fn (mixed $codigo): string => strtolower(trim((string) $codigo)))
        ->filter(fn (string $codigo): bool => $codigo !== '')
        ->all();

    foreach ($mapa as $fila) {
        $identificadores = array_filter([
            $fila->legacy_table,
            $fila->legacy_column,
            $fila->new_table,
            $fila->new_column,
        ], fn (mixed $valor): bool => $valor !== null);

        foreach ($identificadores as $identificador) {
            $nombre = strtolower(trim((string) $identificador));

            // Un nombre TECNICO: `snake_case` en minusculas.
            expect($nombre)->toMatch('/^[a-z][a-z0-9_]*$/');

            // Y NO es un literal de pais: ni un codigo ISO (2 letras), ni un
            // codigo de moneda (3 letras), ni un nombre con espacios.
            expect($nombre)->not->toMatch('/^[a-z]{2}$/')
                ->and($nombre)->not->toMatch('/^[a-z]{3}$/')
                ->and($nombre)->not->toContain(' ');

            foreach (array_merge($codigosIso, $codigosMoneda) as $codigo) {
                expect($nombre)->not->toBe($codigo);
            }

            foreach ($nombresPais as $nombrePais) {
                expect($nombre)->not->toContain($nombrePais);
            }
        }
    }

    // 5. `sync_batches` tiene la RESTRICCION UNICA: el mismo cliente con
    //    el mismo hash de lote NO se puede registrar dos veces. Es lo que
    //    hace idempotente al maestro.
    $lote = [
        'client_id' => 'pos-01',
        'payload_sha256' => str_repeat('a', 64),
        'statement_count' => 2,
        'applied_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('sync_batches')->insert($lote);

    expect(fn () => DB::table('sync_batches')->insert($lote))
        ->toThrow(QueryException::class);

    // El mismo lote de OTRO cliente si entra: son clientes distintos.
    DB::table('sync_batches')->insert(array_merge($lote, ['client_id' => 'pos-02']));

    expect(DB::table('sync_batches')->count())->toBe(2);
});
