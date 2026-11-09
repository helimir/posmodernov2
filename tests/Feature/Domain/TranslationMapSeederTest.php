<?php

use Database\Seeders\LegacyTranslationMapSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.2 — el MAPA DE TRADUCCION SEMBRADO: el nucleo del terminal.
 *
 * Regla que fija: *el mapa es DATO auditable, no codigo*.
 *
 * El terminal legacy empuja SQL crudo de MySQL con los nombres de SU
 * esquema (`selling_info`, `selling_item`, `customer_transactions`, ...). El
 * maestro tiene PostgreSQL con otro esquema, asi que los IDENTIFICADORES se
 * traducen contra el mapa SEMBRADO (`legacy_translation_map`), nunca contra
 * codigo: un `if ($tabla === 'selling_info')` en el traductor seria un
 * defecto (ADR-0006).
 *
 * La DIRECCION es legacy -> destino: el mapa dice "esta columna del legacy
 * es ESTA columna de aqui". Y `new_table IS NULL` significa "esta columna NO
 * se traslada": el dato se deriva en el destino, o no existe alli.
 *
 * Las TRES cosas que se comprueban aqui:
 *
 *  1. El mapa cubre las DIECISIETE tablas del nucleo del terminal (el
 *     checkout POS y la caja). Una tabla sin fila de tabla NO tiene destino:
 *     el traductor rechaza el lote entero.
 *  2. La direccion es la correcta: cada `new_table` EXISTE en el esquema
 *     destino, y cada tabla legacy RENOMBRADA no existe aqui (el destino no
 *     es un calco del legacy).
 *  3. El mapa es DATO TECNICO: ningun identificador es un codigo ISO, un
 *     nombre de pais ni un codigo de moneda.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): el
 * seeder escribe filas y el test las necesita para leerlas, y una restriccion
 * unica rechazada no puede llevarse por delante el resto del test.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::table('legacy_translation_map')->delete();
    DB::table('currencies')->delete();
    DB::table('countries')->delete();
});

test('test_la_traduccion_resuelve_tabla_y_columnas_contra_el_mapa_sembrado', function () {
    $this->seed(LegacyTranslationMapSeeder::class);

    $mapa = DB::table('legacy_translation_map')->orderBy('legacy_table')->orderBy('legacy_column')->get();

    // 1. Las DIECISIETE tablas del nucleo del terminal.
    $tablasDelNucleo = [
        'selling_info',
        'selling_item',
        'selling_price',
        'payments',
        'customer_transactions',
        'sell_logs',
        'customer_to_store',
        'product_to_store',
        'purchase_item',
        'bank_transaction_info',
        'bank_transaction_price',
        'bank_accounts',
        'bank_account_to_store',
        'pos_register',
        'installment_orders',
        'installment_payments',
        'gift_cards',
    ];

    $tablasDelMapa = $mapa->pluck('legacy_table')->unique()->sort()->values()->all();

    sort($tablasDelNucleo);

    expect($tablasDelMapa)->toBe($tablasDelNucleo, 'El mapa no cubre exactamente las tablas del nucleo del terminal.');

    // 1 bis. Cada tabla tiene su fila de TABLA (`legacy_column = '*'`): sin
    //      ella el traductor no tiene destino y rechaza el lote entero.
    foreach ($tablasDelNucleo as $tabla) {
        $regla = $mapa->where('legacy_table', $tabla)->where('legacy_column', '*')->first();

        expect($regla)->not->toBeNull("La tabla '{$tabla}' no tiene su fila de tabla en el mapa.");
    }

    // 2. La DIRECCION: cada destino EXISTE aqui.
    foreach ($mapa as $fila) {
        if ($fila->new_table === null) {
            continue;
        }

        expect(Schema::hasTable($fila->new_table))->toBeTrue(
            "El mapa apunta a la tabla '{$fila->new_table}', que no existe en el esquema destino."
        );
    }

    // 2 bis. Y el destino NO es un calco del legacy: las tablas legacy
    //       RENOMBRADAS no existen aqui.
    $renombradas = [
        'selling_info',
        'selling_item',
        'selling_price',
        'customer_transactions',
        'customer_to_store',
        'product_to_store',
        'purchase_item',
        'bank_transaction_info',
        'bank_transaction_price',
        'bank_account_to_store',
        'pos_register',
        'installment_orders',
        'installment_payments',
    ];

    foreach ($renombradas as $tabla) {
        expect(Schema::hasTable($tabla))->toBeFalse(
            "La tabla legacy '{$tabla}' existe en el destino: la direccion del mapa seria ambigua."
        );
    }

    // 3. Las reglas que SOSTIENEN la traduccion: sin ellas el lote se
    //    rechaza entero.
    $reglas = [
        // El identificador de la venta es un TEXTO del legacy: viaja tal cual.
        ['selling_info', 'invoice_id', 'sales', 'reference_no', 'rename'],
        // La tienda la resuelve el CLIENTE del sync, no el legacy.
        ['selling_info', 'store_id', 'sales', 'store_id', 'inject_store'],
        // El estado de pago del legacy tiene su propio vocabulario.
        ['selling_info', 'payment_status', 'sales', 'status', 'cast_enum:paid:paid:due:confirmed:partial:confirmed'],
        // La fecha de negocio es un DATO del llamante, no un `DAY()` calculado.
        ['pos_register', 'created_at', 'cash_registers', 'business_date', 'inject_business_date'],
        // Las lineas y los cobros resuelven la venta por su identificador.
        ['selling_item', 'invoice_id', 'sale_items', 'sale_id', 'resolve_ref:selling_info'],
        ['payments', 'invoice_id', 'sale_payments', 'sale_id', 'resolve_ref:selling_info'],
    ];

    foreach ($reglas as [$tabla, $columna, $destino, $columnaDestino, $transformacion]) {
        $fila = $mapa->where('legacy_table', $tabla)->where('legacy_column', $columna)->first();

        expect($fila)->not->toBeNull("El mapa no declara '{$tabla}.{$columna}'.")
            ->and($fila->new_table)->toBe($destino)
            ->and($fila->new_column)->toBe($columnaDestino)
            ->and($fila->transform)->toBe($transformacion);
    }

    // 3 bis. Una tabla SIN destino se DESCARTA: el lote no se rechaza, que es
    //       lo que dejaria al terminal congelado (el fallo S1 del ADR-0006).
    $descartada = $mapa->where('legacy_table', 'sell_logs')->where('legacy_column', '*')->first();

    expect($descartada)->not->toBeNull()
        ->and($descartada->new_table)->toBeNull()
        ->and($descartada->new_column)->toBeNull();

    // 4. El mapa es DATO TECNICO: ningun identificador es un literal de pais.
    //    El `*` NO es un identificador: es la marca de la regla de TABLA (la
    //    que el traductor exige para saber el destino de la tabla).
    DB::table('currencies')->insert([
        'code' => 'VES',
        'name' => 'Bolivar',
        'decimals' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('countries')->insert([
        'iso_code' => 'VE',
        'name' => 'Venezuela',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
        ], fn (mixed $valor): bool => $valor !== null && $valor !== '*');

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

    // 5. El seeder es IDEMPOTENTE: se puede sembrar dos veces sin duplicar
    //    ninguna fila.
    $this->seed(LegacyTranslationMapSeeder::class);

    $otraVez = DB::table('legacy_translation_map')->count();

    expect($otraVez)->toBe($mapa->count());
});
