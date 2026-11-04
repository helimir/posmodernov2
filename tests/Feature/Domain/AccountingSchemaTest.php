<?php

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.7.2 / 0.7.3 — el esquema que le faltaba a la contabilidad y a la caja.
 *
 * Reglas que fija:
 * - la cuenta bancaria lleva un saldo de PARTIDA congelado y NO apunta a un
 *   tipo de cuenta que no existe en el repositorio;
 * - el vinculo entre cuenta y tienda NO guarda contadores derivados;
 * - la tienda apunta a un pais que EXISTE (FK real, no una promesa en un
 *   comentario de la migracion);
 * - un saldo de apertura exige SU entidad y prohibe las otras tres;
 * - un saldo de apertura no se duplica para el mismo par;
 * - una tienda tiene UN registro de caja por fecha de negocio;
 * - el catalogo de ingresos NO expone banderas contables.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): en
 * PostgreSQL un error dentro de una transaccion la ABORTA entera, y aqui hace
 * falta que una fila rechazada no se lleve por delante el resto del test.
 */
uses(WithoutWrappingTransaction::class);

/**
 * Inserta un saldo de apertura con los atributos dados.
 *
 * @param  array<string, mixed>  $atributos
 */
function saldo_de_apertura(array $atributos): void
{
    DB::table('opening_balances')->insert(array_merge([
        'store_id' => null,
        'kind' => 'stock',
        'amount' => '10.0000',
        'as_of' => '2026-11-04',
    ], $atributos));
}

/**
 * Un codigo de producto unico: `products.code` es UNIQUE y los tests de este
 * fichero NO van con transaccion envolvente, asi que sus filas sobreviven al
 * test.
 */
function codigo_de_producto(string $prefijo = 'SKU'): string
{
    return $prefijo.'-'.strtoupper(substr(md5(uniqid('', true)), 0, 10));
}

test('test_el_saldo_de_cuenta_se_deriva_de_los_asientos_y_no_de_un_contador_almacenado', function () {
    $columnas = Schema::getColumnListing('bank_accounts');

    // El saldo de PARTIDA de la cuenta es un dato congelado.
    expect($columnas)->toContain('opening_balance')
        ->and(Schema::getColumnType('bank_accounts', 'opening_balance'))->toStartWith('numeric');

    // Y NO apunta a un tipo de cuenta: la tabla `account_types` no existe en
    // el repositorio, y una FK a una tabla inexistente es un agujero.
    expect($columnas)->not->toContain('account_type_id');

    // El vinculo con la tienda tampoco guarda contadores: el saldo se deriva.
    $vinculo = Schema::getColumnListing('bank_account_stores');

    expect($vinculo)->toContain('opening_balance')
        ->and($vinculo)->not->toContain('deposit')
        ->and($vinculo)->not->toContain('withdraw')
        ->and($vinculo)->not->toContain('total_deposit')
        ->and($vinculo)->not->toContain('total_withdraw')
        ->and($vinculo)->not->toContain('transfer_in')
        ->and($vinculo)->not->toContain('transfer_out');

    // El saldo de partida del vinculo es el mismo tipo que el de la cuenta.
    expect(Schema::getColumnType('bank_account_stores', 'opening_balance'))->toStartWith('numeric');
});

test('test_una_tienda_apunta_a_un_pais_que_existe', function () {
    $fk = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS definition
           FROM pg_constraint
          WHERE conrelid = 'stores'::regclass
            AND contype = 'f'
            AND pg_get_constraintdef(oid) LIKE '%(country_id)%'"
    );

    expect($fk)->not->toBeNull('stores.country_id no tiene clave foranea.')
        ->and($fk->definition)->toContain('REFERENCES countries(id)')
        ->and($fk->definition)->toContain('ON DELETE SET NULL');

    // Y la base la hace cumplir: una tienda no puede apuntar a un pais
    // que no existe.
    expect(fn () => Store::create(['name' => 'Tienda sin pais', 'country_id' => 999999]))
        ->toThrow(QueryException::class);

    $pais = DB::table('countries')->insertGetId([
        'iso_code' => strtoupper(substr(md5(uniqid('', true)), 0, 2)),
        'name' => 'Pais de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tienda = Store::create(['name' => 'Tienda con pais', 'country_id' => $pais]);

    expect($tienda->country_id)->toBe($pais);
});

test('test_un_saldo_de_apertura_exige_su_entidad_y_prohibe_las_otras', function () {
    $tienda = Store::create(['name' => 'Tienda de saldos']);
    $producto = Product::create(['code' => codigo_de_producto(), 'name' => 'Producto de saldos']);
    $cliente = Customer::create(['name' => 'Cliente de saldos']);
    $proveedor = Supplier::create(['name' => 'Proveedor de saldos']);
    $cuenta = BankAccount::create(['name' => 'Cuenta de saldos']);

    // Cada `kind` exige SU FK y prohibe las otras tres.
    $casos = [
        'stock' => ['product_id', $producto->id, ['customer_id', 'supplier_id', 'bank_account_id']],
        'customer_balance' => ['customer_id', $cliente->id, ['product_id', 'supplier_id', 'bank_account_id']],
        'supplier_balance' => ['supplier_id', $proveedor->id, ['product_id', 'customer_id', 'bank_account_id']],
        'cash' => ['bank_account_id', $cuenta->id, ['product_id', 'customer_id', 'supplier_id']],
    ];

    foreach ($casos as $kind => [$columna, $valor, $prohibidas]) {
        // 1. Sin la FK de SU kind, la fila no entra.
        expect(fn () => saldo_de_apertura(['store_id' => $tienda->id, 'kind' => $kind]))
            ->toThrow(QueryException::class, null, "Un saldo de tipo {$kind} sin {$columna} no puede existir.");

        // 2. Con la FK de SU kind y las demas a NULL, la fila entra.
        saldo_de_apertura([
            'store_id' => $tienda->id,
            'kind' => $kind,
            $columna => $valor,
        ]);

        // 3. Con la FK de SU kind Y una de las otras, la fila no entra.
        foreach ($prohibidas as $prohibida) {
            expect(fn () => saldo_de_apertura([
                'store_id' => $tienda->id,
                'kind' => $kind,
                $columna => $valor,
                $prohibida => $valor,
            ]))->toThrow(QueryException::class, null, "Un saldo de tipo {$kind} no puede llevar {$prohibida}.");
        }
    }
});

test('test_un_saldo_de_apertura_no_se_duplica_para_el_mismo_par', function () {
    $tienda = Store::create(['name' => 'Tienda de saldos unicos']);
    $producto = Product::create(['code' => codigo_de_producto(), 'name' => 'Producto unico']);
    $cliente = Customer::create(['name' => 'Cliente unico']);

    // Los cuatro indices unicos son PARCIALES: uno por `kind`, sobre el par
    // que de verdad identifica el saldo.
    $indices = DB::select(
        "SELECT indexname, indexdef
           FROM pg_indexes
          WHERE tablename = 'opening_balances'
            AND indexname LIKE 'opening_balances_%_unique'"
    );

    $nombres = array_column($indices, 'indexname');

    expect($nombres)->toContain('opening_balances_kind_stock_unique')
        ->and($nombres)->toContain('opening_balances_kind_customer_balance_unique')
        ->and($nombres)->toContain('opening_balances_kind_supplier_balance_unique')
        ->and($nombres)->toContain('opening_balances_kind_cash_unique');

    foreach ($indices as $indice) {
        expect(str_contains($indice->indexdef, 'WHERE'))
            ->toBeTrue("El indice {$indice->indexname} no es parcial.");
    }

    // El mismo par (tienda, producto) de tipo `stock` no se duplica.
    saldo_de_apertura([
        'store_id' => $tienda->id,
        'kind' => 'stock',
        'product_id' => $producto->id,
    ]);

    expect(fn () => saldo_de_apertura([
        'store_id' => $tienda->id,
        'kind' => 'stock',
        'product_id' => $producto->id,
    ]))->toThrow(QueryException::class);

    // Y el indice es PARCIAL: el mismo identificador en OTRO `kind` no se
    // empareja con el anterior.
    saldo_de_apertura([
        'store_id' => $tienda->id,
        'kind' => 'customer_balance',
        'customer_id' => $cliente->id,
    ]);

    expect(DB::table('opening_balances')
        ->where('store_id', $tienda->id)
        ->where('kind', 'stock')
        ->where('product_id', $producto->id)
        ->count())->toBe(1);
});

test('test_una_tienda_tiene_un_solo_registro_de_caja_por_fecha_de_negocio', function () {
    $tienda = Store::create(['name' => 'Tienda de caja']);

    // La fecha de negocio es un DATO del llamante, no un `DAY()` calculado.
    expect(Schema::getColumnType('cash_registers', 'business_date'))->toBe('date');

    $apertura = [
        'store_id' => $tienda->id,
        'business_date' => '2026-11-04',
        'opened_at' => now(),
        'status' => 'open',
    ];

    DB::table('cash_registers')->insert($apertura);

    // La MISMA tienda y la MISMA fecha de negocio no abren dos cajas.
    expect(fn () => DB::table('cash_registers')->insert($apertura))
        ->toThrow(QueryException::class);

    // Otra fecha de negocio, si.
    DB::table('cash_registers')->insert(array_merge($apertura, ['business_date' => '2026-11-05']));

    // Y otra tienda, el mismo dia, tambien: la caja es por tienda.
    $otra = Store::create(['name' => 'Otra tienda de caja']);
    DB::table('cash_registers')->insert(array_merge($apertura, ['store_id' => $otra->id]));

    expect(DB::table('cash_registers')->where('store_id', $tienda->id)->count())->toBe(2);
});

test('test_el_catalogo_de_ingresos_no_expone_ninguna_bandera_contable', function () {
    $columnas = Schema::getColumnListing('income_sources');

    // Las ONCE banderas del legacy desaparecen: un ingreso no se clasifica
    // con booleanos, se clasifica con su tipo de asiento.
    $banderas = [
        'for_sell', 'for_purchase_return', 'for_due_collection', 'for_loan',
        'for_giftcard_sell', 'for_topup', 'for_stock_transfer',
        'for_purchase_delete', 'for_expense_delete', 'profitable', 'show_in_income',
    ];

    foreach ($banderas as $bandera) {
        expect(in_array($bandera, $columnas, true))
            ->toBeFalse("El catalogo de ingresos expone la bandera contable {$bandera}.");
    }

    // El "tipo de movimiento" del legacy (`credit`/`debit`) tampoco vive aqui:
    // se consulta en `ledger_entry_types`.
    expect(in_array('type', $columnas, true))
        ->toBeFalse('El tipo de movimiento se consulta en el catalogo de tipos de asiento.');

    // El UNICO booleano es el de INTERFAZ (`is_active`), que no es un flag
    // contable: el unico sitio donde se clasifica un movimiento es el
    // catalogo de tipos de asiento.
    $booleanos = array_values(array_filter(
        $columnas,
        fn (string $columna): bool => in_array(Schema::getColumnType('income_sources', $columna, true), ['boolean', 'bool'], true)
    ));

    expect($booleanos)->toBe(['is_active']);

    // Y el catalogo es un arbol, con su clave.
    expect($columnas)->toContain('code')
        ->and($columnas)->toContain('name')
        ->and($columnas)->toContain('parent_id');
});
