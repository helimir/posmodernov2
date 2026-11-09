<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.3 / 0.9.3-T — la semilla del perfil venezolano con EVIDENCIA TRAZABLE.
 *
 * REGLA que fija: *un dato no verificado se marca como pendiente, no se afirma*.
 *
 * La tasa de IVA del 16 % NO aparece en la providencia transcriptionada
 * (SNAT/2026/00093, arts. 54 y 57): la providencia remite al Decreto del IVA
 * (G.O. 5.363 del 25 de noviembre de 1999), que no se ha leido. Sembrarla
 * como `verified` seria AFIRMAR un dato que nadie ha verificado: se siembra
 * `pending_confirmation` CON su `source_ref`, para que la fila sea localizable
 * por la fuente y se pueda confirmar cuando el Decreto este a mano.
 *
 * La fila se tiene que poder LOCALIZAR POR SU `source_ref`: sin eso, el estado
 * de la evidencia no sirve de nada, porque nadie puede saber que dato es el
 * que falta por confirmar.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): un
 * `migrate:fresh` DENTRO de una transaccion la aborta entera, porque el DDL de
 * PostgreSQL no vive dentro de la transaccion que la prueba abrio.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    // Lo que siembran los seeders se borra: el test siguiente no puede
    // encontrar datos que no son suyos. Una moneda o un pais sembrado choca
    // con el fixture de otro test (el `VES` del mapa de traduccion, por
    // ejemplo), y una fila que sobrevive al test hace que el siguiente mida
    // sobre una base que no es la suya.
    DB::statement(
        'TRUNCATE customer_ledger_entries, sales, customers, stores, fiscal_profiles,'
        .' countries, currencies RESTART IDENTITY CASCADE'
    );

    DB::table('legacy_translation_map')->delete();
    DB::table('users')->where('email', 'test@example.com')->delete();
});

test('test_la_tasa_de_iva_sembrada_figura_pendiente_de_confirmacion_con_su_fuente', function () {
    // 1. El FLUJO de la instalacion: migraciones y seeders, en ese orden.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    // 2. La tasa del 16 % se LOCALIZA por su `source_ref`: la fila es
    //    rastreable hasta el Decreto del IVA (G.O. 5.363/1999).
    $tasas = DB::table('tax_rates')
        ->where('source_ref', 'G.O. 5.363/1999')
        ->get();

    expect($tasas)->not->toBeEmpty('Ninguna tasa sembrada cita la fuente del Decreto del IVA.');

    $iva = $tasas->first(fn ($tasa) => (string) $tasa->percentage === '16.000000');

    expect($iva)->not->toBeNull('Ninguna tasa sembrada declara el 16 % con la fuente del Decreto del IVA.')
        // 3. PENDIENTE de confirmacion, y NUNCA `verified`.
        ->and((string) $iva->evidence_status)->toBe('pending_confirmation')
        ->and((string) $iva->evidence_status)->not->toBe('verified')
        // 4. El porcentaje es 16 y la vigencia esta puesta.
        ->and((string) $iva->percentage)->toBe('16.000000')
        ->and($iva->valid_from)->not->toBeNull();

    // 5. Y es la tasa de la categoria `gravable` del perfil venezolano.
    $categoria = DB::table('tax_categories')->where('id', $iva->tax_category_id)->first();
    $perfil = DB::table('fiscal_profiles')->where('id', $categoria->fiscal_profile_id)->first();

    expect((string) $categoria->code)->toBe('gravable')
        ->and((string) $perfil->code)->toBe('VE_MEDIOS_DIGITALES')
        ->and((string) $categoria->evidence_status)->toBe('pending_confirmation');

    // 6. NINGUNA fila del perfil venezolano declara `verified` SIN `source_ref`:
    //    un dato verificado sin fuente no es verificable por nadie.
    $categorias = DB::table('tax_categories')
        ->where('fiscal_profile_id', $perfil->id)
        ->pluck('id');

    $filas = collect()
        ->merge(DB::table('fiscal_profile_fields')->where('fiscal_profile_id', $perfil->id)->get())
        ->merge(DB::table('tax_categories')->where('fiscal_profile_id', $perfil->id)->get())
        ->merge(DB::table('tax_rates')->whereIn('tax_category_id', $categorias)->get());

    expect($filas)->not->toBeEmpty('El perfil venezolano no tiene ninguna fila con estado de evidencia.');

    foreach ($filas as $fila) {
        if ((string) $fila->evidence_status === 'verified') {
            expect((string) $fila->source_ref)->not->toBe(
                '',
                'Una fila declara \'verified\' y no dice de donde sale: el estado de la evidencia sin fuente no es trazable.'
            );
        }
    }
});

test('test_el_perfil_venezolano_declara_el_token_de_serie_y_la_frase_del_contribuyente', function () {
    Artisan::call('migrate:fresh', ['--seed' => true]);

    $perfil = DB::table('fiscal_profiles')->where('code', 'VE_MEDIOS_DIGITALES')->first();

    expect($perfil)->not->toBeNull('El perfil venezolano no esta sembrado.');

    // 1. El token obligatorio del prefijo de serie (C4): el dominio lo COMPRA
    //    contra el valor leido, nunca contra un literal.
    $token = DB::table('fiscal_profile_fields')
        ->where('fiscal_profile_id', $perfil->id)
        ->where('key', 'series_prefix_required_token')
        ->first();

    expect($token)->not->toBeNull('El perfil venezolano no declara el token obligatorio del prefijo de serie.')
        ->and((string) $token->value)->toBe('serie')
        ->and((string) $token->evidence_status)->not->toBe('')
        ->and((string) $token->source_ref)->not->toBe('');

    // 2. La frase del contribuyente formal del IVA.
    $frase = DB::table('fiscal_profile_fields')
        ->where('fiscal_profile_id', $perfil->id)
        ->where('key', 'formal_contributor_phrase')
        ->first();

    expect($frase)->not->toBeNull('El perfil venezolano no declara la frase del contribuyente formal.')
        ->and((string) $frase->value)->toBe('Contribuyentes Formales del IVA')
        ->and((string) $frase->evidence_status)->not->toBe('')
        ->and((string) $frase->source_ref)->not->toBe('');

    // 3. Y el marcador `(E)` del bien exento, exonerado o no gravado.
    $marcador = DB::table('fiscal_profile_fields')
        ->where('fiscal_profile_id', $perfil->id)
        ->where('key', 'exemption_marker')
        ->first();

    expect($marcador)->not->toBeNull()
        ->and((string) $marcador->value)->toBe('(E)')
        ->and((string) $marcador->source_ref)->not->toBe('');
});
