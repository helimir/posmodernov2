<?php

use App\Sync\Legacy\LegacyStatementTranslator;
use Illuminate\Support\Facades\DB;

/**
 * 0.8.7-T — el TRADUCTOR del SQL del legacy contra el MAPA SEMBRADO.
 *
 * REGLA que fija: *un lote es TODO o NADA*.
 *
 * El terminal legacy empuja SQL crudo de MySQL con los nombres de SU
 * esquema (`selling_info`, `selling_item`, ...). El maestro es PostgreSQL
 * con otro esquema, asi que los IDENTIFICADORES se traducen contra el mapa
 * SEMBRADO (`legacy_translation_map`) y el traductor devuelve operaciones
 * ESTRUCTURADAS, nunca SQL.
 *
 * La regla que este test fija es la del TODO O NADA: si UNA sola operacion
 * del lote no se puede traducir, el traductor LANZA y NO devuelve ninguna
 * operacion. Un lote a medias es PEOR que un lote rechazado: el terminal
 * solo trunca su log cuando la respuesta fue exitosa, asi que un lote
 * parcialmente aplicado se reenvia y se aplica DOS veces.
 *
 * El MAPA se siembra aqui, no en un seeder: lo que se fija es la REGLA (la
 * traduccion sale de la FILA, nunca de un `if` por tabla), no el contenido
 * del mapa. El seeder del mapa es de otra unidad.
 */
beforeEach(function () {
    DB::table('legacy_translation_map')->delete();

    DB::table('legacy_translation_map')->insert([
        // La REGLA DE TABLA de `selling_info`: la tabla del legacy es `sales`
        // aqui. La columna de destino de una regla de tabla no significa
        // nada: la tabla entera se traslada.
        ['legacy_table' => 'selling_info', 'legacy_column' => '*', 'new_table' => 'sales', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'invoice_id', 'new_table' => 'sales', 'new_column' => 'reference_no', 'transform' => 'rename'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'store_id', 'new_table' => 'sales', 'new_column' => 'store_id', 'transform' => 'inject_store'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'customer_id', 'new_table' => 'sales', 'new_column' => 'customer_id', 'transform' => 'rename'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'payment_status', 'new_table' => 'sales', 'new_column' => 'status', 'transform' => 'cast_enum:paid:paid:due:confirmed'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'invoice_note', 'new_table' => 'sales', 'new_column' => 'invoice_no', 'transform' => 'rename'],
        // Una columna DESCARTADA: el mapa la declara y dice que NO se
        // traslada.
        ['legacy_table' => 'selling_info', 'legacy_column' => 'customer_mobile', 'new_table' => null, 'new_column' => null, 'transform' => null],

        // La REGLA DE TABLA de `selling_item` y la RESOLUCION de su id: el
        // `invoice_id` del legacy es un TEXTO y aqui la FK es un bigint.
        ['legacy_table' => 'selling_item', 'legacy_column' => '*', 'new_table' => 'sale_items', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'selling_item', 'legacy_column' => 'invoice_id', 'new_table' => 'sale_items', 'new_column' => 'sale_id', 'transform' => 'resolve_ref:selling_info'],
        ['legacy_table' => 'selling_item', 'legacy_column' => 'store_id', 'new_table' => 'sale_items', 'new_column' => 'store_id', 'transform' => 'inject_store'],

        // La CAJA por fecha de negocio: la fecha NO es la del legacy (su
        // `created_at`), es un DATO del lote.
        ['legacy_table' => 'pos_register', 'legacy_column' => '*', 'new_table' => 'cash_registers', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'pos_register', 'legacy_column' => 'created_at', 'new_table' => 'cash_registers', 'new_column' => 'business_date', 'transform' => 'inject_business_date'],

        // El COBRO de una venta: la venta es la del lote, no la del legacy.
        ['legacy_table' => 'payments', 'legacy_column' => '*', 'new_table' => 'sale_payments', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'payments', 'legacy_column' => 'invoice_id', 'new_table' => 'sale_payments', 'new_column' => 'sale_id', 'transform' => 'inject_reference'],
        ['legacy_table' => 'payments', 'legacy_column' => 'amount', 'new_table' => 'sale_payments', 'new_column' => 'amount', 'transform' => 'rename'],

        // Una tabla que aqui NO existe (`sell_logs`): el mapa lo dice, y su
        // operacion se descarta. No es un rechazo: el log del terminal se
        // trunca igual, y un rechazo dejaria al terminal CONGELADO.
        ['legacy_table' => 'sell_logs', 'legacy_column' => '*', 'new_table' => null, 'new_column' => null, 'transform' => null],
    ]);
});

/**
 * El contexto del lote: la tienda, la venta, la fecha de negocio y el
 * numero de secuencia.
 *
 * @return array<string, mixed>
 */
function contextoDelLote(int $storeId, int $saleId = 0): array
{
    return [
        'store_id' => $storeId,
        'sale_id' => $saleId,
        'business_date' => '2026-11-05',
        'sequence' => 1,
    ];
}

/**
 * Traduce un lote y devuelve lo que devuelve el traductor, o la EXCEPCION
 * que lanzo. Para poder afirmar las dos cosas (que lanza y que no devuelve
 * ninguna operacion) sin repetir el `try` en cada test.
 *
 * @param  array<int, array<string, mixed>>  $lote
 * @param  array<string, mixed>  $contexto
 * @return array{resultado: mixed, fallo: ?RuntimeException}
 */
function traducirLote(array $lote, array $contexto = []): array
{
    $traductor = app(LegacyStatementTranslator::class);

    $resultado = null;
    $fallo = null;

    try {
        $resultado = $traductor->translate($lote, $contexto);
    } catch (RuntimeException $e) {
        $fallo = $e;
    }

    return ['resultado' => $resultado, 'fallo' => $fallo];
}

test('test_un_lote_con_una_operacion_no_reconocible_se_rechaza_entero_y_no_se_aplica_parcialmente', function () {
    // 1. Una operacion con una TABLA que NO esta en el mapa. La primera
    //    operacion del lote es VALIDA: si el traductor devolviera lo que
    //    puede, el lote se aplicaria A MEDIAS y el terminal lo reenviaria.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_info` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', 1],
        ],
        [
            'sql' => 'INSERT INTO `tabla_del_legacy_que_no_existe` (id, valor) VALUES (?, ?)',
            'args' => [1, 2],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 2. Una operacion que FILTRA por una columna DESCARTADA (la fila del
    //    mapa la declara descartada): no se puede filtrar por algo que no
    //    existe, y el filtro se perderia.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_info` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', 1],
        ],
        [
            'sql' => 'DELETE FROM `selling_info` WHERE `customer_mobile` = ?',
            'args' => ['04120000000'],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 3. Y lo mismo con una columna que NO TIENE fila en el mapa: tambien
    //    se descarta, y tampoco puede filtrar.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_info` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', 1],
        ],
        [
            'sql' => 'DELETE FROM `selling_info` WHERE `total_items` = ?',
            'args' => [3],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 4. Una operacion que necesita un valor del CONTEXTO que el lote no
    //    trae: sin `business_date` no hay fecha de negocio, y una caja sin
    //    fecha de negocio es una caja de un dia que no existe.
    $lote = [
        [
            'sql' => 'INSERT INTO `pos_register` (created_at) VALUES (?)',
            'args' => ['2026-11-05 10:00:00'],
        ],
    ];

    $traduccion = traducirLote($lote, ['store_id' => 1, 'sale_id' => 1, 'sequence' => 1]);

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 5. Un valor del legacy que NO esta en los pares del `cast_enum`: el
    //    destino no sabe que poner en su columna de enum.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_info` (invoice_id, payment_status) VALUES (?, ?)',
            'args' => ['12021/00000004', 'partial'],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 6. Un `resolve_ref` que NO encuentra la venta: un id inventado seria
    //    una linea de venta colgada de una venta que no existe.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_item` (invoice_id) VALUES (?)',
            'args' => ['12021/99999999'],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
        ->and($traduccion['resultado'])->toBeNull();

    // 7. Y una tabla que el mapa declara NO trasladable NO es un rechazo:
    //    su operacion se descarta y el resto del lote se traduce. Un
    //    rechazo aqui dejaria al terminal CONGELADO, que es justo el
    //    fallo que el ADR-0006 existe para tapar.
    $lote = [
        [
            'sql' => 'INSERT INTO `selling_info` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', 1],
        ],
        [
            'sql' => 'INSERT INTO `sell_logs` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', 1],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote(1));

    expect($traduccion['fallo'])->toBeNull()
        ->and($traduccion['resultado'])->toHaveCount(1)
        ->and($traduccion['resultado'][0]['table'])->toBe('sales');
});

test('test_el_contrato_legacy_se_traduce_contra_el_mapa_sembrado', function () {
    // La tienda del MAESTRO (la del contexto del lote) y la del LEGACY (la
    // que viaja en el lote): si la tienda del lote llegara al maestro, el
    // maestro escribiria la venta en la tienda equivocada.
    $storeLegacy = DB::table('stores')->insertGetId(['name' => 'Tienda del legacy']);
    $storeMaestro = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    // La venta que el lote REFERENCIA: el `resolve_ref` busca el id real
    // de `sales` por su `reference_no`, que es el texto del legacy.
    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $storeMaestro,
        'reference_no' => '12021/00000004',
        'sale_date' => '2026-11-05',
        'status' => 'draft',
    ]);

    $lote = [
        [
            // La sentencia REAL del legacy (`_inc/model/invoice.php:308`).
            'sql' => 'INSERT INTO `selling_info` (invoice_id, store_id, customer_id, payment_status) VALUES (?, ?, ?, ?)',
            'args' => ['12021/00000004', $storeLegacy, 7, 'paid'],
        ],
        [
            'sql' => 'INSERT INTO `selling_item` (invoice_id, store_id) VALUES (?, ?)',
            'args' => ['12021/00000004', $storeLegacy],
        ],
        [
            'sql' => 'INSERT INTO `payments` (invoice_id, amount) VALUES (?, ?)',
            'args' => ['12021/00000004', 12.5],
        ],
        [
            'sql' => 'INSERT INTO `pos_register` (created_at) VALUES (?)',
            'args' => ['2026-11-05 10:00:00'],
        ],
        [
            // Una columna que el mapa DECLARA descartada y otra que NO
            // tiene fila: sus valores NO viajan, y las columnas y los
            // valores siguen en paralelo.
            'sql' => 'INSERT INTO `selling_info` (invoice_id, customer_mobile, total_items) VALUES (?, ?, ?)',
            'args' => ['12021/00000004', '04120000000', 3],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote($storeMaestro, $saleId));

    expect($traduccion['fallo'])->toBeNull()
        ->and($traduccion['resultado'])->toBeArray()
        ->and($traduccion['resultado'])->toHaveCount(5);

    // La CABECERA de la venta: la tabla destino es `sales` y las columnas
    // son las del mapa sembrado.
    expect($traduccion['resultado'][0]['type'])->toBe('insert')
        ->and($traduccion['resultado'][0]['table'])->toBe('sales')
        ->and($traduccion['resultado'][0]['columns'])->toBe([
            'reference_no',
            'store_id',
            'customer_id',
            'status',
        ])
        // El `store_id` es el del CONTEXTO (la tienda del maestro), no el
        // que envio el legacy.
        ->and($traduccion['resultado'][0]['values'])->toBe([
            '12021/00000004',
            $storeMaestro,
            7,
            'paid',
        ]);

    // La LINEA: el `invoice_id` de TEXTO se ha RESUELTO al id real de
    // `sales`. Un id inventado seria una venta que no existe.
    expect($traduccion['resultado'][1]['type'])->toBe('insert')
        ->and($traduccion['resultado'][1]['table'])->toBe('sale_items')
        ->and($traduccion['resultado'][1]['columns'])->toBe(['sale_id', 'store_id'])
        ->and($traduccion['resultado'][1]['values'])->toBe([$saleId, $storeMaestro]);

    // El COBRO: la venta es la del LOTE (`inject_reference`) y el importe
    // viaja como TEXTO decimal, nunca como `float`: un flotante no es
    // dinero, y el motor no lo volveria a leer igual.
    expect($traduccion['resultado'][2]['type'])->toBe('insert')
        ->and($traduccion['resultado'][2]['table'])->toBe('sale_payments')
        ->and($traduccion['resultado'][2]['columns'])->toBe(['sale_id', 'amount'])
        ->and($traduccion['resultado'][2]['values'])->toBe([$saleId, '12.5000']);

    // La CAJA: la fecha de negocio es la del LOTE, no la del legacy.
    expect($traduccion['resultado'][3]['type'])->toBe('insert')
        ->and($traduccion['resultado'][3]['table'])->toBe('cash_registers')
        ->and($traduccion['resultado'][3]['columns'])->toBe(['business_date'])
        ->and($traduccion['resultado'][3]['values'])->toBe(['2026-11-05']);

    // Y las columnas que NO se trasladan: su valor no viaja, y el resto
    // sigue en su sitio.
    expect($traduccion['resultado'][4]['type'])->toBe('insert')
        ->and($traduccion['resultado'][4]['table'])->toBe('sales')
        ->and($traduccion['resultado'][4]['columns'])->toBe(['reference_no'])
        ->and($traduccion['resultado'][4]['values'])->toBe(['12021/00000004']);
});

test('test_un_update_y_un_delete_se_traducen_contra_el_mapa_sembrado', function () {
    $storeLegacy = DB::table('stores')->insertGetId(['name' => 'Tienda del legacy']);
    $storeMaestro = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $storeMaestro,
        'reference_no' => '12021/00000004',
        'sale_date' => '2026-11-05',
        'status' => 'draft',
    ]);

    $lote = [
        [
            // El legacy actualiza el estado de pago de la venta.
            'sql' => 'UPDATE `selling_info` SET `payment_status` = ? WHERE `invoice_id` = ?',
            'args' => ['due', '12021/00000004'],
        ],
        [
            'sql' => 'DELETE FROM `selling_item` WHERE `invoice_id` = ? AND `store_id` = ?',
            'args' => ['12021/00000004', $storeLegacy],
        ],
    ];

    $traduccion = traducirLote($lote, contextoDelLote($storeMaestro, $saleId));

    expect($traduccion['fallo'])->toBeNull()
        ->and($traduccion['resultado'])->toHaveCount(2);

    // El UPDATE: la asignacion viaja con el VALOR del mapa (`due` es
    // `confirmed` en el destino) y el filtro viaja con la columna destino.
    expect($traduccion['resultado'][0]['type'])->toBe('update')
        ->and($traduccion['resultado'][0]['table'])->toBe('sales')
        ->and($traduccion['resultado'][0]['assignments'])->toBe(['status' => 'confirmed'])
        ->and($traduccion['resultado'][0]['where'])->toBe(['reference_no' => '12021/00000004']);

    // El DELETE: el filtro tambien RESUELVE el id, porque el destino filtra
    // por la FK `bigint` y no por el texto del legacy.
    expect($traduccion['resultado'][1]['type'])->toBe('delete')
        ->and($traduccion['resultado'][1]['table'])->toBe('sale_items')
        ->and($traduccion['resultado'][1]['where'])->toBe([
            'sale_id' => $saleId,
            'store_id' => $storeMaestro,
        ]);
});

test('test_el_des_escapeo_del_legacy_ocurre_una_sola_vez_en_la_frontera', function () {
    $storeMaestro = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    // El legacy HTML-escapea sus entradas (`Request` con `ENT_COMPAT`), asi
    // que el maestro recibe `&amp;` donde deberia haber `&`.
    $lote = [[
        'sql' => 'INSERT INTO `selling_info` (invoice_id, invoice_note) VALUES (?, ?)',
        'args' => ['12021/00000004', 'Tienda &amp; Co'],
    ]];

    $traduccion = traducirLote($lote, contextoDelLote($storeMaestro));

    expect($traduccion['fallo'])->toBeNull()
        ->and($traduccion['resultado'][0]['values'])->toBe([
            '12021/00000004',
            'Tienda & Co',
        ]);

    // Y UNA SOLA VEZ: un valor ya escapado DOS veces se des-escapea UNA.
    // Si se des-escapeara dos veces, `&amp;amp;` llegaria como `&` y el
    // dato seria peor que el original.
    $lote = [[
        'sql' => 'INSERT INTO `selling_info` (invoice_id, invoice_note) VALUES (?, ?)',
        'args' => ['12021/00000004', 'Tienda &amp;amp; Co'],
    ]];

    $traduccion = traducirLote($lote, contextoDelLote($storeMaestro));

    expect($traduccion['fallo'])->toBeNull()
        ->and($traduccion['resultado'][0]['values'][1])->toBe('Tienda &amp; Co');
});

test('test_una_forma_no_soportada_rechaza_el_lote_entero', function () {
    $storeMaestro = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);
    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $storeMaestro,
        'reference_no' => '12021/00000004',
        'sale_date' => '2026-11-05',
        'status' => 'draft',
    ]);

    $contexto = contextoDelLote($storeMaestro, $saleId);

    // 1. `LIMIT 1`: el legacy lo usa y PostgreSQL no lo soporta.
    // 2. Un `SELECT`: el log del legacy solo lleva escrituras.
    // 3. Una EXPRESION en el `SET`: `col = col - ?` no se sabe traducir.
    // 4. Una sentencia APILADA: dos sentencias en una operacion.
    // 5. Un `WHERE` con `OR`: el destino filtra por columnas, y una
    //    disjuncion no es una conjuncion.
    //
    // Cada una se rechaza POR SU MOTIVO: un rechazo que no dice por que
    // no prueba nada.
    $sentencias = [
        ['sql' => 'DELETE FROM `selling_item` WHERE `invoice_id` = ? LIMIT 1', 'args' => ['12021/00000004'], 'motivo' => 'LIMIT'],
        ['sql' => 'SELECT * FROM `selling_info` WHERE `invoice_id` = ?', 'args' => ['12021/00000004'], 'motivo' => 'no es de las tres formas soportadas'],
        ['sql' => 'UPDATE `selling_info` SET `total_items` = `total_items` - ? WHERE `invoice_id` = ?', 'args' => [1, '12021/00000004'], 'motivo' => 'no es de las tres formas soportadas'],
        ['sql' => 'INSERT INTO `selling_info` (invoice_id) VALUES (?); DELETE FROM `selling_info` WHERE `invoice_id` = ?', 'args' => ['12021/00000004', '12021/00000004'], 'motivo' => '`;` apilado'],
        ['sql' => 'DELETE FROM `selling_item` WHERE `invoice_id` = ? OR `store_id` = ?', 'args' => ['12021/00000004', 1], 'motivo' => '`OR`'],
    ];

    foreach ($sentencias as $sentencia) {
        $traduccion = traducirLote([$sentencia], $contexto);

        expect($traduccion['fallo'])->toBeInstanceOf(RuntimeException::class)
            ->and($traduccion['resultado'])->toBeNull()
            ->and($traduccion['fallo']->getMessage())->toContain($sentencia['motivo']);
    }
});
