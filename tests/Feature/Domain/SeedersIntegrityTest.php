<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.2-T y 0.9.3 — la SEMILLA COMPLETA entrega el esquema con sus catalogos.
 *
 * REGLA que fija: *el esquema se entrega por migraciones y datos sembrados,
 * no por scripts*.
 *
 * Y la regla de los catalogos: un catalogo NO lleva banderas booleanas de
 * tipo contable. El legacy esparcia la clasificacion de un movimiento en
 * VEINTI Y UNA banderas (once en `income_sources` y diez en `expense_categorys`):
 * una bandera no se puede consultar ("¿este gasto es un gasto?": si, porque
 * el booleano existe) y dos banderas activas a la vez no significan nada. En
 * el destino, el movimiento tiene UN TIPO y el tipo se CONSULTA en el catalogo
 * `ledger_entry_types`.
 *
 * Y la regla del arranque: un saldo de apertura SIN CERTIFICAR no abre el
 * arranque. La certificacion es un ACTO HUMANO (fecha y responsable nominal),
 * asi que un script no puede certificarlo: solo puede EXIGIRLO.
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

test('test_la_semilla_completa_el_esquema_y_los_catalogos_sin_banderas_contables', function () {
    // 1. El FLUJO: migraciones y seeders, en ese orden.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    // 2. Las VEINTI Y UNA banderas del legacy: once en `income_sources` y diez
    //    en `expense_categorys`.
    $banderasLegacy = [
        // `income_sources` (11).
        'for_sell', 'for_purchase_return', 'for_due_collection', 'for_loan',
        'for_giftcard_sell', 'for_topup', 'for_stock_transfer', 'for_purchase_delete',
        'for_expense_delete', 'profitable', 'show_in_income',
        // `expense_categorys` (10).
        'sell_return', 'sell_delete', 'loan_delete', 'loan_payment',
        'giftcard_sell_delete', 'topup_delete', 'product_purchase', 'stock_transfer',
        'due_paid', 'is_hide',
    ];

    // 3. Lo que SI puede quedar: `is_active` es de INTERFAZ (permitir ocultar
    //    un elemento del catalogo) y `is_withholding` es el MARCADOR del
    //    tratamiento de retencion, no una clasificacion contable del
    //    movimiento: el tipo del movimiento lo decide `ledger_entry_types`.
    $catalogos = [
        'tax_categories' => ['is_withholding'],
        'income_sources' => ['is_active'],
        'expense_categories' => ['is_active'],
        'ledger_entry_types' => ['is_active'],
    ];

    foreach ($catalogos as $tabla => $permitidas) {
        $columnas = DB::select(
            "select column_name from information_schema.columns
              where table_schema = 'public' and table_name = ? and data_type = 'boolean'
              order by column_name",
            [$tabla]
        );

        $booleanas = array_map(fn ($columna) => (string) $columna->column_name, $columnas);

        // 3a. Ninguna de las 21 banderas del legacy sobrevive.
        expect(array_values(array_intersect($booleanas, $banderasLegacy)))->toBe(
            [],
            "La tabla {$tabla} conserva una bandera contable del legacy."
        );

        // 3b. Y las que quedan son las de interfaz o el marcador de retencion.
        expect(array_values(array_diff($booleanas, $permitidas)))->toBe(
            [],
            "La tabla {$tabla} tiene una columna booleana que no es de interfaz."
        );
    }

    // 4. Y los catalogos que la semilla completa estan SEMBRADOS: el catalogo
    //    de tipos de asiento y el de categorias de IVA.
    expect(DB::table('ledger_entry_types')->count())->toBe(7);

    $categorias = DB::table('tax_categories')->orderBy('code')->pluck('code')->all();

    expect($categorias)->toBe(['exento', 'exonerado', 'gravable', 'iva_retenido', 'no_gravado']);
});

test('test_los_cinco_paises_tienen_su_perfil_con_su_moneda_y_sus_series', function () {
    Artisan::call('migrate:fresh', ['--seed' => true]);

    $paises = [
        'VE' => ['moneda' => 'VES', 'perfil' => 'VE_MEDIOS_DIGITALES'],
        'CL' => ['moneda' => 'CLP', 'perfil' => 'CL_DTE'],
        'MX' => ['moneda' => 'MXN', 'perfil' => 'MX_CFDI40'],
        'CO' => ['moneda' => 'COP', 'perfil' => 'CO_DIAN21'],
        'PA' => ['moneda' => 'PAB', 'perfil' => 'PA_FE'],
    ];

    foreach ($paises as $iso => $esperado) {
        // 1. El pais, con su moneda por defecto.
        $pais = DB::table('countries')->where('iso_code', $iso)->first();

        expect($pais)->not->toBeNull("El pais {$iso} no esta sembrado.");

        $moneda = DB::table('currencies')->where('id', $pais->default_currency_id)->first();

        expect((string) $moneda->code)->toBe($esperado['moneda']);

        // 2. Su formato numerico: el correlativo es DATO, no una constante.
        $formato = DB::table('number_formats')->where('country_id', $pais->id)->first();

        expect($formato)->not->toBeNull("El pais {$iso} no tiene formato numerico.")
            ->and((string) $formato->invoice_number_format)->not->toBe('');

        // 3. Su perfil fiscal.
        $perfil = DB::table('fiscal_profiles')
            ->where('country_id', $pais->id)
            ->where('code', $esperado['perfil'])
            ->first();

        expect($perfil)->not->toBeNull("El pais {$iso} no tiene el perfil {$esperado['perfil']}.");

        // 4. LAS DOS SERIES: la del documento y la del control, cada una con su
        //    rango autorizado, su autoridad y su identificador.
        $series = DB::table('fiscal_series')->where('fiscal_profile_id', $perfil->id)->get();

        expect($series)->toHaveCount(2, "El perfil {$esperado['perfil']} no tiene sus dos series.");

        foreach (['document', 'control'] as $rol) {
            $serie = $series->firstWhere('series_role', $rol);

            expect($serie)->not->toBeNull("El perfil {$esperado['perfil']} no tiene la serie {$rol}.")
                ->and((int) $serie->from_number)->toBeGreaterThan(0)
                ->and((int) $serie->to_number)->toBeGreaterThan((int) $serie->from_number)
                ->and((int) $serie->next_number)->toBeGreaterThanOrEqual((int) $serie->from_number)
                ->and((string) $serie->assigned_by)->not->toBe('');
        }
    }
});

test('test_los_saldos_de_apertura_sin_certificar_no_abren_el_arranque', function () {
    Artisan::call('migrate:fresh', ['--seed' => true]);

    // 1. Los saldos de apertura EXISTEN (el negocio necesita el saldo de caja
    //    de la tienda de demostracion para arrancar).
    $saldos = DB::table('opening_balances')->get();

    expect($saldos)->not->toBeEmpty('La semilla no siembra ningun saldo de apertura.');

    // 2. Y NINGUNO viene certificado: la certificacion es un acto humano de la
    //    direccion financiera, con fecha y responsable nominal.
    foreach ($saldos as $saldo) {
        expect($saldo->certified_at)->toBeNull(
            'Un saldo sembrado no puede venir certificado: certificar es un acto humano.'
        )->and($saldo->certified_by)->toBeNull(
            'Un saldo sembrado no puede venir certificado: certificar es un acto humano.'
        );
    }

    // 3. El arranque operativo sigue BLOQUEADO.
    $codigo = Artisan::call('ops:check-opening-balances');

    expect($codigo)->not->toBe(0, 'Un saldo sin certificar no puede abrir el arranque.')
        ->and(Artisan::output())->toContain('faltan saldos de apertura certificados');
});
