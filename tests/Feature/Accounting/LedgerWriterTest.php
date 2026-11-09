<?php

use App\Domain\Accounting\LedgerWriter;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.7.1-T / 0.7.4-T — contabilidad: un movimiento tiene UN tipo, y el GASTO
 * manda sobre el movimiento bancario.
 *
 * Reglas que fija:
 *  - un movimiento contable tiene un TIPO, no once banderas: el legacy
 *    esparcia 21 booleanos entre `income_sources` y `expense_categorys`, y
 *    aqui el CATALOGO `ledger_entry_types` los sustituye (design D7/D8);
 *  - un gasto registrado sin movimiento bancario sigue siendo gasto: el gasto
 *    NACE en `expenses` y el banco no lo inventa (design D19).
 *
 * Los importes viajan como TEXTO decimal: nunca como float.
 */
test('test_cada_movimiento_contable_tiene_un_unico_entry_type_y_ninguna_bandera_dispersa', function () {
    // 1. El CATALOGO de tipos existe y esta sembrado.
    expect(Schema::hasTable('ledger_entry_types'))->toBeTrue()
        ->and(Schema::hasTable('ledger_entries'))->toBeTrue()
        ->and(Schema::hasTable('ledger_lines'))->toBeTrue();

    $tipos = DB::table('ledger_entry_types')->orderBy('id')->get();

    expect($tipos->count())->toBeGreaterThanOrEqual(5)
        ->and($tipos->pluck('code')->all())->toContain(
            'sale',
            'sale_payment',
            'expense',
            'bank_movement',
            'refund',
            'adjustment',
            'opening_balance'
        );

    // 2. `ledger_entries` NO expone NINGUNA bandera booleana: el tipo del
    //    movimiento es UNA COLUMNA, no once columnas de `true`/`false`.
    $banderas = [];

    foreach (Schema::getColumnListing('ledger_entries') as $column) {
        // La definicion completa devuelve el tipo SQL ('boolean'); la version
        // corta devuelve el `typname` interno de PostgreSQL ('bool'). Se
        // miran las dos: una bandera no se cuela por ninguna de las dos.
        $tipo = Schema::getColumnType('ledger_entries', $column, true);

        if (in_array($tipo, ['boolean', 'bool'], true)) {
            $banderas[] = $column;
        }
    }

    expect($banderas)->toBe([]);

    // 3. Un asiento tiene EXACTAMENTE un tipo: `entry_type_id` es NOT NULL y
    //    es la FK al catalogo.
    $columnas = collect(Schema::getColumns('ledger_entries'))->keyBy('name');

    expect($columnas['entry_type_id']['nullable'])->toBeFalse()
        // `foreignId` es un entero de 64 bits en PostgreSQL.
        ->and(in_array($columnas['entry_type_id']['type'], ['integer', 'bigint'], true))->toBeTrue();

    // 4. Un asiento puede tener VARIAS lineas en cuentas distintas: un
    //    movimiento se puede repartir entre varias cuentas.
    $store = Store::create(['name' => 'Tienda de contabilidad']);
    $writer = new LedgerWriter(DB::connection());

    $occurredAt = new DateTimeImmutable('2026-11-04T10:15:00-04:00');
    $businessDate = new DateTimeImmutable('2026-11-04T00:00:00-04:00');

    $entryId = $writer->write(
        'sale',
        $store->id,
        $occurredAt,
        $businessDate,
        [
            ['account' => '4010000', 'debit' => '120.0000', 'credit' => '0'],
            ['account' => '2110000', 'debit' => '0', 'credit' => '120.0000'],
        ]
    );

    $entry = DB::table('ledger_entries')->where('id', $entryId)->first();
    $lines = DB::table('ledger_lines')->where('ledger_entry_id', $entryId)->orderBy('id')->get();

    // El tipo se TOMA del catalogo: la cabecera guarda el id, no el codigo.
    expect((int) $entry->entry_type_id)->toBe((int) $tipos->firstWhere('code', 'sale')->id)
        ->and($lines)->toHaveCount(2)
        ->and($lines->pluck('account_code')->all())->toBe(['4010000', '2110000']);

    // El importe del asiento es la suma de los debitos.
    expect(bccomp((string) $entry->amount, '120.0000', 4))->toBe(0);

    // La fecha de negocio viaja EXPLICITA: es un dato, no un calculo.
    expect((string) $entry->business_date)->toBe('2026-11-04')
        ->and((new DateTimeImmutable((string) $entry->occurred_at))->getTimestamp())
        ->toBe($occurredAt->getTimestamp());

    // 5. Un codigo que NO esta en el catalogo no es un asiento.
    $escribir = fn (string $tipo, array $lineas) => fn () => $writer->write(
        $tipo,
        $store->id,
        $occurredAt,
        $businessDate,
        $lineas
    );

    expect($escribir('tipo_inexistente', [
        ['account' => '4010000', 'debit' => '10.0000', 'credit' => '0'],
        ['account' => '2110000', 'debit' => '0', 'credit' => '10.0000'],
    ]))->toThrow(RuntimeException::class);

    // Y un tipo APAGADO tampoco admite asientos nuevos.
    DB::table('ledger_entry_types')->where('code', 'adjustment')->update(['is_active' => false]);

    expect($escribir('adjustment', [
        ['account' => '4010000', 'debit' => '10.0000', 'credit' => '0'],
        ['account' => '2110000', 'debit' => '0', 'credit' => '10.0000'],
    ]))->toThrow(RuntimeException::class);

    // 6. El asiento CUADRA: la suma de los debitos es la suma de los
    //    creditos, sin tolerancia.
    expect($escribir('sale', [
        ['account' => '4010000', 'debit' => '120.0000', 'credit' => '0'],
    ]))->toThrow(RuntimeException::class);

    // Una linea no es debito y credito a la vez.
    expect($escribir('sale', [
        ['account' => '4010000', 'debit' => '10.0000', 'credit' => '10.0000'],
        ['account' => '2110000', 'debit' => '0', 'credit' => '10.0000'],
    ]))->toThrow(RuntimeException::class);

    // Ni una linea sin importe.
    expect($escribir('sale', [
        ['account' => '4010000', 'debit' => '0', 'credit' => '0'],
    ]))->toThrow(RuntimeException::class);

    // Ni importes negativos.
    expect($escribir('sale', [
        ['account' => '4010000', 'debit' => '-10.0000', 'credit' => '0'],
        ['account' => '2110000', 'debit' => '0', 'credit' => '-10.0000'],
    ]))->toThrow(RuntimeException::class);

    // Y ningun asiento invalido dejo cabecera: la validacion es ANTES de
    // escribir.
    expect(DB::table('ledger_entries')->count())->toBe(1);
});

test('test_un_gasto_sin_movimiento_bancario_sigue_siendo_gasto_con_su_asiento', function () {
    $store = Store::create(['name' => 'Tienda de gastos']);
    $writer = new LedgerWriter(DB::connection());

    // El GASTO nace en `expenses`: es el HECHO, y el asiento se deriva de
    // el. No al reves.
    $expenseId = DB::table('expenses')->insertGetId([
        'store_id' => $store->id,
        'expense_category_id' => null,
        'amount' => '75.5000',
        'description' => 'Compra de insumos de oficina',
        'reference' => 'FAC-0001',
        'business_date' => '2026-11-04',
        'incurred_at' => '2026-11-04T09:00:00-04:00',
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $entryId = $writer->write(
        'expense',
        $store->id,
        new DateTimeImmutable('2026-11-04T09:00:00-04:00'),
        new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
        [
            ['account' => '5100000', 'debit' => '75.5000', 'credit' => '0', 'description' => 'Gasto por insumos'],
            ['account' => '2100000', 'debit' => '0', 'credit' => '75.5000'],
        ],
        'expense',
        $expenseId
    );

    $entry = DB::table('ledger_entries')->where('id', $entryId)->first();
    $expenseTypeId = DB::table('ledger_entry_types')->where('code', 'expense')->value('id');

    // El asiento es EL DEL GASTO: tipo del catalogo, importe del gasto y
    // referencia al gasto.
    expect((int) $entry->entry_type_id)->toBe((int) $expenseTypeId)
        ->and(bccomp((string) $entry->amount, '75.5000', 4))->toBe(0)
        ->and($entry->reference_type)->toBe('expense')
        ->and((int) $entry->reference_id)->toBe($expenseId)
        ->and((string) $entry->business_date)->toBe('2026-11-04');

    // Y sus lineas CUADRAN: el gasto vale exactamente lo que dice el gasto.
    $debe = '0';
    $haber = '0';

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $entryId)->get() as $line) {
        $debe = bcadd($debe, (string) $line->debit, 4);
        $haber = bcadd($haber, (string) $line->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, '75.5000', 4))->toBe(0);

    // Un MOVIMIENTO BANCARIO no inventa un gasto: el gasto sigue siendo el
    // que se registro, y el banco no anade ninguno.
    $gastosAntes = DB::table('expenses')->count();

    $bankEntryId = $writer->write(
        'bank_movement',
        $store->id,
        new DateTimeImmutable('2026-11-04T15:00:00-04:00'),
        new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
        [
            ['account' => '1100000', 'debit' => '30.0000', 'credit' => '0'],
            ['account' => '2100000', 'debit' => '0', 'credit' => '30.0000'],
        ],
        'bank_account',
        null
    );

    expect(DB::table('expenses')->count())->toBe($gastosAntes)
        ->and(DB::table('expenses')->where('reference', 'MOV-BANCARIO-0001')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->where('id', $bankEntryId)->exists())->toBeTrue();
});

/**
 * 0.7.4-T — el importe es TEXTO decimal, redondeado HALF-UP a la escala de
 * la columna, y la cabecera guarda su descripcion.
 *
 * Reglas que fija:
 *  - un importe es TEXTO decimal: un `float` no es dinero y se RECHAZA. En
 *    binario `0.1 + 0.2` es `0.30000000000000004`, y truncarlo a 4 decimales
 *    deja un centimo menos de lo que se cobro;
 *  - el redondeo a la escala de la columna (`numeric(25,4)`) es HALF-UP, no
 *    un truncamiento: truncar `10.00505` daria `10.0050` y se perderia medio
 *    centimo;
 *  - la cabecera guarda la descripcion del movimiento, y su ausencia es
 *    `null`, no una cadena vacia.
 */
test('test_el_asiento_rechaza_un_float_redondea_a_la_escala_de_la_columna_y_guarda_la_descripcion', function () {
    $store = Store::create(['name' => 'Tienda de importes']);
    $writer = new LedgerWriter(DB::connection());

    $occurredAt = new DateTimeImmutable('2026-11-04T10:15:00-04:00');
    $businessDate = new DateTimeImmutable('2026-11-04T00:00:00-04:00');

    // 1. La descripcion del movimiento se guarda EXACTA en la cabecera, y el
    //    importe con mas de 4 decimales entra por la MISMA llamada.
    $entryId = $writer->write(
        entryType: 'sale',
        storeId: $store->id,
        occurredAt: $occurredAt,
        businessDate: $businessDate,
        lines: [
            ['account' => '4010000', 'debit' => '10.00505', 'credit' => '0'],
            ['account' => '2110000', 'debit' => '0', 'credit' => '10.00505'],
        ],
        description: 'Venta #123'
    );

    $entry = DB::table('ledger_entries')->where('id', $entryId)->first();

    expect($entry->description)->toBe('Venta #123');

    // 2. El redondeo es HALF-UP a la escala de la columna, NO un
    //    truncamiento: `10.00505` queda en `10.0051`; truncarlo daria
    //    `10.0050`.
    $line = DB::table('ledger_lines')->where('ledger_entry_id', $entryId)->orderBy('id')->first();

    expect((string) $line->debit)->toBe('10.0051')
        ->and(bccomp((string) $line->debit, '10.0051', 4))->toBe(0)
        ->and(bccomp((string) $line->debit, '10.0050', 4))->not->toBe(0)
        // Y el importe del asiento es la suma de los debitos ya redondeados.
        ->and((string) $entry->amount)->toBe('10.0051');

    // 3. Sin descripcion, la cabecera la deja en `null` (no en "").
    $entryIdSinDescripcion = $writer->write(
        entryType: 'sale',
        storeId: $store->id,
        occurredAt: $occurredAt,
        businessDate: $businessDate,
        lines: [
            ['account' => '4010000', 'debit' => '1.0000', 'credit' => '0'],
            ['account' => '2110000', 'debit' => '0', 'credit' => '1.0000'],
        ]
    );

    expect(DB::table('ledger_entries')->where('id', $entryIdSinDescripcion)->value('description'))->toBeNull();

    // 4. Un `float` NO es un importe: se rechaza y NO se escribe cabecera.
    $cabecerasAntes = DB::table('ledger_entries')->count();

    foreach ([12.5, 0.1 + 0.2] as $float) {
        expect(fn () => $writer->write(
            entryType: 'sale',
            storeId: $store->id,
            occurredAt: $occurredAt,
            businessDate: $businessDate,
            lines: [
                ['account' => '4010000', 'debit' => $float, 'credit' => '0'],
                ['account' => '2110000', 'debit' => '0', 'credit' => $float],
            ]
        ))->toThrow(RuntimeException::class, 'must be a decimal string, never a float');
    }

    expect(DB::table('ledger_entries')->count())->toBe($cabecerasAntes);
});

/**
 * 0.7.2-T — el gasto NACE en `expenses`, y el movimiento bancario no lo
 * inventa.
 *
 * Regla que fija: *el gasto nace en `expenses`, no en el banco*. `expenses` es
 * la FUENTE DE VERDAD del gasto: un gasto registrado existe ahi aunque no
 * tenga ningun movimiento bancario, y un movimiento bancario SIN gasto no
 * inventa ninguno. El legacy hacia al reves, y por eso un gasto registrado
 * sin movimiento se perdia.
 */
test('test_el_gasto_nace_en_expenses_y_el_movimiento_bancario_no_lo_inventa', function () {
    $store = Store::create(['name' => 'Tienda del gasto']);

    // 1. `expenses` es una tabla de primera clase: el gasto tiene su importe
    //    decimal, su referencia y su FECHA DE NEGOCIO.
    expect(Schema::hasTable('expenses'))->toBeTrue()
        ->and(Schema::getColumnType('expenses', 'amount'))->toBe('numeric')
        ->and(Schema::getColumnListing('expenses'))->toContain('business_date')
        ->toContain('reference')
        ->toContain('description');

    // 2. El gasto se REGISTRA en `expenses`: nace ahi, no en el banco.
    $expenseId = DB::table('expenses')->insertGetId([
        'store_id' => $store->id,
        'expense_category_id' => null,
        'amount' => '42.0000',
        'description' => 'Papeleria de la tienda',
        'reference' => 'FAC-0002',
        'business_date' => '2026-11-04',
        'incurred_at' => '2026-11-04T08:00:00-04:00',
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gasto = DB::table('expenses')->where('id', $expenseId)->first();

    expect($gasto)->not->toBeNull()
        ->and((string) $gasto->amount)->toBe('42.0000')
        ->and((string) $gasto->business_date)->toBe('2026-11-04')
        ->and((string) $gasto->reference)->toBe('FAC-0002')
        ->and((string) $gasto->description)->toBe('Papeleria de la tienda');

    // 3. Y el movimiento bancario NO lo inventa: el banco mueve dinero, no
    //    crea gastos.
    $writer = new LedgerWriter(DB::connection());

    $bankEntryId = $writer->write(
        entryType: 'bank_movement',
        storeId: $store->id,
        occurredAt: new DateTimeImmutable('2026-11-04T15:00:00-04:00'),
        businessDate: new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
        lines: [
            ['account' => '1100000', 'debit' => '30.0000', 'credit' => '0'],
            ['account' => '2100000', 'debit' => '0', 'credit' => '30.0000'],
        ],
        referenceType: 'bank_account',
    );

    // El movimiento SI se registro (no es un no-op)...
    expect(DB::table('ledger_entries')->where('id', $bankEntryId)->exists())->toBeTrue();

    // ... y no invento ningun gasto.
    expect(DB::table('expenses')->count())->toBe(1)
        ->and(DB::table('expenses')->where('id', $expenseId)->count())->toBe(1)
        ->and(DB::table('expenses')->where('reference', 'MOV-BANCARIO-0001')->count())->toBe(0);

    // 4. Y la clasificacion contable del gasto NO es una bandera dispersa: el
    //    unico booleano de los catalogos es `is_active`, que es de INTERFAZ.
    $banderas = [];

    foreach (['expense_categories', 'income_sources'] as $tabla) {
        foreach (Schema::getColumnListing($tabla) as $columna) {
            if (in_array($columna, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            $tipo = Schema::getColumnType($tabla, $columna, true);

            if (in_array($tipo, ['boolean', 'bool'], true) && $columna !== 'is_active') {
                $banderas[] = "{$tabla}.{$columna}";
            }
        }
    }

    expect($banderas)->toBe([]);
});

/**
 * 0.7.7-T — el tipo de asiento se TOMA del catalogo, no de un literal.
 *
 * Regla que fija: *la clasificacion se consulta, no se desparrama en el
 * codigo*. El tipo del asiento es una FK al catalogo `ledger_entry_types`: un
 * codigo que no esta en el catalogo no es un asiento, y un tipo NUEVO en el
 * catalogo es un asiento valido sin tocar una sola linea de codigo.
 */
test('test_el_tipo_de_asiento_se_toma_del_catalogo_y_no_de_un_literal', function () {
    $store = Store::create(['name' => 'Tienda del catalogo']);
    $writer = new LedgerWriter(DB::connection());

    $occurredAt = new DateTimeImmutable('2026-11-04T10:15:00-04:00');
    $businessDate = new DateTimeImmutable('2026-11-04T00:00:00-04:00');

    $lineas = [
        ['account' => '4010000', 'debit' => '10.0000', 'credit' => '0'],
        ['account' => '2110000', 'debit' => '0', 'credit' => '10.0000'],
    ];

    // 1. La cabecera guarda el ID del catalogo, no el codigo: no hay columna
    //    donde escribir el tipo como texto.
    $columnas = Schema::getColumnListing('ledger_entries');

    expect($columnas)->toContain('entry_type_id')
        ->and($columnas)->not->toContain('entry_type')
        ->and($columnas)->not->toContain('type');

    $entryId = $writer->write('sale', $store->id, $occurredAt, $businessDate, $lineas);
    $entry = DB::table('ledger_entries')->where('id', $entryId)->first();
    $saleTypeId = DB::table('ledger_entry_types')->where('code', 'sale')->value('id');

    expect((int) $entry->entry_type_id)->toBe((int) $saleTypeId);

    // 2. Un codigo que NO esta en el catalogo no es un asiento: la
    //    clasificacion se CONSULTA, y si no esta, no hay asiento.
    $cabecerasAntes = DB::table('ledger_entries')->count();

    expect(fn () => $writer->write('tipo_inexistente', $store->id, $occurredAt, $businessDate, $lineas))
        ->toThrow(RuntimeException::class);

    expect(DB::table('ledger_entries')->count())->toBe($cabecerasAntes);

    // 3. Y un tipo NUEVO en el catalogo es un asiento valido SIN tocar el
    //    codigo: la clasificacion es DATO, no una lista escondida.
    DB::table('ledger_entry_types')->insert([
        'code' => 'zz_tipo_del_catalogo',
        'name' => 'Tipo nuevo del catalogo',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $nuevoId = $writer->write('zz_tipo_del_catalogo', $store->id, $occurredAt, $businessDate, $lineas);
    $nuevo = DB::table('ledger_entries')->where('id', $nuevoId)->first();
    $nuevoTypeId = DB::table('ledger_entry_types')->where('code', 'zz_tipo_del_catalogo')->value('id');

    expect((int) $nuevo->entry_type_id)->toBe((int) $nuevoTypeId)
        ->and(DB::table('ledger_entries')->count())->toBe($cabecerasAntes + 1);
});
