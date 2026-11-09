<?php

use App\Models\Sale;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * La costura — el comando de humo hace una VENTA REAL y enseña su anatomía.
 *
 * Reglas que fija:
 *
 *  - `ops:smoke-sale` no simula nada: llama al `CheckoutService` de verdad, y
 *    la venta que deja escrita es la MISMA que despues ve el navegador. No
 *    hay una venta de mentira en un lado y una de verdad en otro;
 *  - la venta completa deja cabecera, lineas, totales, pago, asiento contable
 *    y SUS DOS RESERVAS: la del numero de documento y la del numero de
 *    control (design §B.1);
 *  - las cifras CUADRAN: la suma de las lineas mas el ajuste de redondeo es
 *    el total redondeado, y el asiento cuadra (debitos = creditos);
 *  - el consumo FIFO baja EXACTAMENTE lo vendido, y el cache del stock sigue
 *    la invariante (`quantity_in_stock` = suma de los lotes disponibles);
 *  - el comando sale con codigo cero y NOMBRA el numero de documento, el de
 *    control, el total y el ajuste de redondeo: una costura que no se puede
 *    leer no es una costura.
 */

/**
 * El numero de control tal y como lo compone la serie que lo reparte: el
 * identificador de dos digitos mas el secuencial de longitud fija.
 *
 * El comando NO lo inventa: lo toma de la reserva que dejo la propia serie.
 */
function humo_numero_de_control(): string
{
    $reserva = DB::table('fiscal_document_reservations')
        ->where('series_role', 'control')
        ->orderBy('id')
        ->first();

    expect($reserva)->not->toBeNull();

    $serie = DB::table('fiscal_series')->where('id', $reserva->series_id)->first();

    return $serie->control_identifier.'-'.str_pad(
        (string) $reserva->number,
        (int) $serie->sequence_length,
        '0',
        STR_PAD_LEFT
    );
}

/**
 * 0.4.3-T — el comando deja una venta COMPLETA, no un simulacro.
 */
test('test_el_comando_de_humo_hace_una_venta_real_con_cabecera_lineas_totales_pago_y_asiento', function () {
    $this->artisan('ops:smoke-sale', ['--quantity' => 2])->assertExitCode(0);

    $sale = Sale::query()->latest('id')->first();

    expect($sale)->not->toBeNull();

    // 1. LA CABECERA: su numero, su estado y su fecha de negocio.
    expect($sale->invoice_no)->not->toBeNull()
        ->and($sale->status)->toBe('paid')
        ->and($sale->sale_date)->not->toBeNull()
        ->and($sale->store_id)->not->toBeNull();

    // 2. LA LINEA, con el porcentaje CONGELADO y la evidencia de esa tasa.
    expect($sale->items)->toHaveCount(1);

    $item = $sale->items->first();

    expect((string) $item->quantity)->toBe('2.0000')
        ->and((string) $item->unit_price)->toBe('25.0000')
        ->and((string) $item->tax_rate_snapshot)->toBe('16.0000')
        ->and($item->evidence_status)->toBe('pending_confirmation')
        ->and((string) $item->base_amount)->toBe('50.0000')
        ->and((string) $item->tax_amount)->toBe('8.0000')
        ->and((string) $item->line_total)->toBe('58.0000');

    // 3. LOS TOTALES.
    expect($sale->total)->not->toBeNull()
        ->and((string) $sale->total->sub_total)->toBe('50.0000')
        ->and((string) $sale->total->discount)->toBe('0.0000')
        ->and((string) $sale->total->tax_total)->toBe('8.0000')
        ->and((string) $sale->total->grand_total)->toBe('58.0000')
        ->and($sale->total->evidence_status)->toBe('pending_confirmation');

    // 4. EL PAGO: el importe cobrado es el total.
    expect($sale->payments)->toHaveCount(1)
        ->and((string) $sale->payments->first()->amount)->toBe('58.0000');

    // 5. EL ASIENTO CONTABLE, con su tipo del catalogo y su referencia.
    $asiento = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $sale->id)
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((int) $asiento->entry_type_id)->toBe(
            (int) DB::table('ledger_entry_types')->where('code', 'sale')->value('id')
        );

    expect(DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->count())->toBe(3);

    // 6. LAS DOS RESERVAS: la del documento y la del control.
    $reservas = DB::table('fiscal_document_reservations')->orderBy('id')->get();

    expect($reservas)->toHaveCount(2)
        ->and($reservas->pluck('series_role')->all())->toBe(['document', 'control']);

    // Y la del documento queda ENLAZADA a la venta: el reintento de la
    // emision fiscal encuentra la reserva que ya tiene.
    expect($reservas->first()->reference_type)->toBe('sale')
        ->and((int) $reservas->first()->reference_id)->toBe($sale->id);
});

/**
 * 0.4.3-T — las cifras de la venta CUADRAN y el stock baja lo vendido.
 */
test('test_las_cifras_de_la_venta_cuadran_y_el_stock_baja_exactamente_lo_vendido', function () {
    $this->artisan('ops:smoke-sale', ['--quantity' => 2])->assertExitCode(0);

    $sale = Sale::query()->latest('id')->first();
    $totales = $sale->total;

    // 1. La suma de las lineas mas el ajuste de redondeo es el total.
    $suma = '0';

    foreach ($sale->items as $item) {
        $suma = bcadd($suma, (string) $item->line_total, 4);
    }

    expect(bcadd($suma, (string) $totales->rounding_adjustment, 4))->toBe((string) $totales->grand_total)
        ->and(bcsub((string) $totales->grand_total, (string) $totales->tax_total, 4))
        ->toBe((string) $totales->sub_total);

    // 2. EL ASIENTO CUADRA: la suma de los debitos es la de los creditos, y
    //    es el total de la venta.
    $asiento = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $sale->id)
        ->first();

    $debe = '0';
    $haber = '0';

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->get() as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, (string) $totales->grand_total, 4))->toBe(0);

    // 3. EL FIFO bajo EXACTAMENTE lo vendido: ni una unidad mas, ni una menos.
    $consumo = DB::table('sale_item_lot_consumptions')
        ->where('sale_item_id', $sale->items->first()->id)
        ->first();

    expect($consumo)->not->toBeNull()
        ->and((string) $consumo->quantity)->toBe('2.0000');

    $lote = DB::table('inventory_lots')->where('id', $consumo->lot_id)->first();

    expect((string) $lote->quantity_consumed)->toBe('2.0000')
        ->and((string) $lote->available)->toBe('8.0000');

    // 4. Y el CACHE del stock sigue la invariante: es la suma de los lotes.
    $cache = DB::table('product_stores')
        ->where('product_id', $lote->product_id)
        ->where('store_id', $lote->store_id)
        ->value('quantity_in_stock');

    expect((string) $cache)->toBe('8.0000');
});

/**
 * 0.4.3-T — el comando se LEE: nombra el documento, el control, el total y el
 * ajuste de redondeo.
 */
test('test_el_comando_sale_con_codigo_cero_y_nombra_el_documento_el_control_el_total_y_el_ajuste', function () {
    $codigo = Artisan::call('ops:smoke-sale');
    $salida = Artisan::output();

    $sale = Sale::query()->latest('id')->first();
    $totales = $sale->total;

    expect($codigo)->toBe(0)
        // El numero de documento, tal y como se reservo.
        ->and($salida)->toContain((string) $sale->invoice_no)
        // El numero de control, tal y como lo compuso la serie.
        ->and($salida)->toContain(humo_numero_de_control())
        // El total y el ajuste de redondeo.
        ->and($salida)->toContain((string) $totales->grand_total)
        ->and($salida)->toContain('Ajuste de redondeo')
        ->and($salida)->toContain((string) $totales->rounding_adjustment);
});
