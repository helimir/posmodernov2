<?php

use App\Domain\Inventory\LotFifoAllocator;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Inventory\TransferService;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.6.9 / 0.6.9-T — la transferencia entre almacenes.
 *
 * Reglas que fija:
 * - el stock sale del **lote origen**: `transfer_items.source_lot_id` apunta
 *   al lote de salida, y el consumo es FIFO (`received_at ASC, id ASC`): del
 *   mas ANTIGUO, no del mas reciente;
 * - una transferencia es **un movimiento, no N cabeceras**: UNA sola fila en
 *   `transfers` y N lineas en `transfer_items` (el legacy generaba una
 *   cabecera por articulo, P0 #17 del mapa funcional);
 * - la recepcion crea el **lote de destino**, que sabe de que lote salio
 *   (`inventory_lots.source_lot_id`);
 * - lo que no se puede despachar **no se escribe**: sin stock suficiente, la
 *   transferencia entera se revierte y no queda cabecera, ni linea, ni
 *   consumo, ni cambio de cache.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement('TRUNCATE transfer_item_lot_consumptions, transfer_items, transfers,'
        .' sale_item_lot_consumptions, sale_items, sale_totals, sales, inventory_lots,'
        .' purchase_items, purchase_totals, purchases, product_stores, products,'
        .' stores RESTART IDENTITY CASCADE');
});

/**
 * El escenario: un almacen ORIGEN con DOS productos y, de cada uno, DOS
 * lotes (el mas antiguo y el mas reciente), y un almacen DESTINO.
 *
 * Dos productos para que la regla de "una sola cabecera" sea observable: con
 * una sola linea, una cabecera por linea daria el mismo numero de cabeceras.
 *
 * @return array<string, int> Los ids del escenario.
 */
function escenarioTransferencia(): array
{
    $origen = Store::create(['name' => 'Almacen origen']);
    $destino = Store::create(['name' => 'Almacen destino']);

    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $origen->id,
        'purchase_date' => '2026-11-03',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $escenario = [
        'origen' => (int) $origen->id,
        'destino' => (int) $destino->id,
    ];

    // Producto A: lote viejo de 10 y lote reciente de 6, a 7.5000.
    // Producto B: lote viejo de 8 y lote reciente de 4, a 3.2500.
    foreach ([['A', 'ZZT-1', '7.5', 10, 6, '2026-01-01', '2026-03-01'],
        ['B', 'ZZT-2', '3.25', 8, 4, '2026-01-15', '2026-04-01']] as [$letra, $code, $coste, $viejo, $reciente, $antiguo, $nuevo]) {
        $producto = Product::create(['code' => $code, 'name' => 'Producto '.$letra]);

        // La ficha del producto en el almacen ORIGEN.
        ProductStore::create([
            'product_id' => $producto->id,
            'store_id' => $origen->id,
            'purchase_price' => $coste,
            'sell_price' => 15,
            'quantity_in_stock' => 0,
        ]);

        foreach ([[$antiguo, $viejo], [$nuevo, $reciente]] as [$recibido, $cantidad]) {
            $itemId = DB::table('purchase_items')->insertGetId([
                'purchase_id' => $purchaseId,
                'product_id' => $producto->id,
                'store_id' => $origen->id,
                'quantity' => $cantidad,
                'unit_price' => $coste,
                'discount' => 0,
                'tax_rate_snapshot' => 16,
                'tax_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lotId = DB::table('inventory_lots')->insertGetId([
                'product_id' => $producto->id,
                'store_id' => $origen->id,
                'purchase_item_id' => $itemId,
                'quantity_received' => $cantidad,
                'quantity_consumed' => 0,
                'quantity_returned' => 0,
                'unit_cost' => $coste,
                'received_at' => $recibido,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $escenario['producto_'.strtolower($letra)] = (int) $producto->id;
            $escenario['lote_'.strtolower($letra).'_'.($recibido === $antiguo ? 'viejo' : 'reciente')] = (int) $lotId;
        }
    }

    // El cache del origen, coherente con sus lotes.
    $invariant = new StockInvariant(DB::connection());

    foreach ([$escenario['producto_a'], $escenario['producto_b']] as $productoId) {
        $invariant->refresh($productoId, $escenario['origen']);
    }

    return $escenario;
}

test('test_la_transferencia_mueve_el_stock_desde_el_lote_origen_y_registra_una_sola_cabecera', function () {
    $e = escenarioTransferencia();

    $invariant = new StockInvariant(DB::connection());
    $service = new TransferService(
        DB::connection(),
        new LotFifoAllocator(DB::connection()),
        $invariant,
    );

    // 1. EL DESPACHO: el stock sale del lote ORIGEN, del mas ANTIGUO.
    $transferId = $service->dispatch(
        sourceStoreId: $e['origen'],
        destinationStoreId: $e['destino'],
        lines: [
            ['product_id' => $e['producto_a'], 'quantity' => '4'],
            ['product_id' => $e['producto_b'], 'quantity' => '2'],
        ],
        transferDate: new DateTimeImmutable('2026-11-05'),
    );

    // 1.1 UNA SOLA CABECERA: una transferencia es un movimiento, no N. Con
    //     DOS lineas, una cabecera por linea daria DOS cabeceras.
    expect(DB::table('transfers')->count())->toBe(1);

    $transfer = DB::table('transfers')->find($transferId);

    expect((int) $transfer->store_id)->toBe($e['origen'])
        ->and((int) $transfer->destination_store_id)->toBe($e['destino'])
        ->and((string) $transfer->status)->toBe('in_transit')
        ->and((string) $transfer->transfer_date)->toBe('2026-11-05');

    // 1.2 Las lineas de la transferencia apuntan al LOTE DE SALIDA: el mas
    //     ANTIGUO de cada producto, no el mas reciente.
    $items = DB::table('transfer_items')->where('transfer_id', $transferId)->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and((int) $items[0]->product_id)->toBe($e['producto_a'])
        ->and((int) $items[0]->source_lot_id)->toBe($e['lote_a_viejo'])
        ->and((string) $items[0]->quantity)->toBe('4.0000')
        ->and((string) $items[0]->unit_cost)->toBe('7.5000')
        ->and((int) $items[1]->product_id)->toBe($e['producto_b'])
        ->and((int) $items[1]->source_lot_id)->toBe($e['lote_b_viejo'])
        ->and((string) $items[1]->quantity)->toBe('2.0000')
        ->and((string) $items[1]->unit_cost)->toBe('3.2500');

    // 1.3 Los lotes de origen bajan EXACTAMENTE lo transferido, y los lotes
    //     recientes no se tocan.
    expect((string) DB::table('inventory_lots')->where('id', $e['lote_a_viejo'])->value('available'))->toBe('6.0000')
        ->and((string) DB::table('inventory_lots')->where('id', $e['lote_a_reciente'])->value('available'))->toBe('6.0000')
        ->and((string) DB::table('inventory_lots')->where('id', $e['lote_b_viejo'])->value('available'))->toBe('6.0000')
        ->and((string) DB::table('inventory_lots')->where('id', $e['lote_b_reciente'])->value('available'))->toBe('4.0000');

    // 1.4 Y la traza dice de que lote salio: el consumo es de una
    //     TRANSFERENCIA, no de una venta.
    $traza = DB::table('transfer_item_lot_consumptions')->orderBy('id')->get();

    expect($traza)->toHaveCount(2)
        ->and((int) $traza[0]->transfer_item_id)->toBe((int) $items[0]->id)
        ->and((int) $traza[0]->lot_id)->toBe($e['lote_a_viejo'])
        ->and((int) $traza[0]->product_id)->toBe($e['producto_a'])
        ->and((int) $traza[0]->store_id)->toBe($e['origen'])
        ->and((string) $traza[0]->quantity)->toBe('4.0000')
        ->and((string) $traza[0]->unit_cost)->toBe('7.5000')
        ->and((int) $traza[1]->transfer_item_id)->toBe((int) $items[1]->id)
        ->and((int) $traza[1]->lot_id)->toBe($e['lote_b_viejo'])
        ->and((string) $traza[1]->quantity)->toBe('2.0000')
        ->and((string) $traza[1]->unit_cost)->toBe('3.2500');

    // 1.5 Y los caches del origen cuadran con sus lotes.
    expect($invariant->holds($e['producto_a'], $e['origen']))->toBeTrue()
        ->and($invariant->holds($e['producto_b'], $e['origen']))->toBeTrue()
        ->and($invariant->cachedStock($e['producto_a'], $e['origen']))->toBe('12.0000')
        ->and($invariant->cachedStock($e['producto_b'], $e['origen']))->toBe('10.0000');

    // 2. LA RECEPCION: el stock entra en el destino.
    $service->receive($transferId);

    // 2.1 Los LOTES DE DESTINO saben de que lote salieron.
    $lotesDestino = DB::table('inventory_lots')->where('store_id', $e['destino'])->orderBy('id')->get();

    expect($lotesDestino)->toHaveCount(2)
        ->and((int) $lotesDestino[0]->product_id)->toBe($e['producto_a'])
        ->and((int) $lotesDestino[0]->source_lot_id)->toBe($e['lote_a_viejo'])
        ->and($lotesDestino[0]->purchase_item_id)->toBeNull()
        ->and((string) $lotesDestino[0]->quantity_received)->toBe('4.0000')
        ->and((string) $lotesDestino[0]->quantity_consumed)->toBe('0.0000')
        ->and((string) $lotesDestino[0]->available)->toBe('4.0000')
        ->and((string) $lotesDestino[0]->unit_cost)->toBe('7.5000')
        ->and((int) $lotesDestino[1]->product_id)->toBe($e['producto_b'])
        ->and((int) $lotesDestino[1]->source_lot_id)->toBe($e['lote_b_viejo'])
        ->and($lotesDestino[1]->purchase_item_id)->toBeNull()
        ->and((string) $lotesDestino[1]->available)->toBe('2.0000')
        ->and((string) $lotesDestino[1]->unit_cost)->toBe('3.2500');

    // 2.2 La cabecera queda recibida.
    expect((string) DB::table('transfers')->where('id', $transferId)->value('status'))->toBe('received');

    // 2.3 Los caches de las DOS tiendas cuadran con la suma de sus lotes.
    expect($invariant->holds($e['producto_a'], $e['origen']))->toBeTrue()
        ->and($invariant->holds($e['producto_b'], $e['origen']))->toBeTrue()
        ->and($invariant->holds($e['producto_a'], $e['destino']))->toBeTrue()
        ->and($invariant->holds($e['producto_b'], $e['destino']))->toBeTrue()
        ->and($invariant->cachedStock($e['producto_a'], $e['origen']))->toBe('12.0000')
        ->and($invariant->cachedStock($e['producto_b'], $e['origen']))->toBe('10.0000')
        ->and($invariant->cachedStock($e['producto_a'], $e['destino']))->toBe('4.0000')
        ->and($invariant->cachedStock($e['producto_b'], $e['destino']))->toBe('2.0000');

    // 3. SIN STOCK SUFICIENTE: no se sobrevende y NO queda nada escrito.
    $antes = [
        'transfers' => DB::table('transfers')->count(),
        'items' => DB::table('transfer_items')->count(),
        'traza' => DB::table('transfer_item_lot_consumptions')->count(),
        'lotes' => DB::table('inventory_lots')->count(),
    ];

    expect(fn () => $service->dispatch(
        sourceStoreId: $e['origen'],
        destinationStoreId: $e['destino'],
        lines: [['product_id' => $e['producto_a'], 'quantity' => '999']],
        transferDate: new DateTimeImmutable('2026-11-05'),
    ))->toThrow(RuntimeException::class, 'Stock insuficiente');

    expect(DB::table('transfers')->count())->toBe($antes['transfers'])
        ->and(DB::table('transfer_items')->count())->toBe($antes['items'])
        ->and(DB::table('transfer_item_lot_consumptions')->count())->toBe($antes['traza'])
        ->and(DB::table('inventory_lots')->count())->toBe($antes['lotes'])
        ->and((string) DB::table('inventory_lots')->where('id', $e['lote_a_viejo'])->value('available'))->toBe('6.0000')
        ->and($invariant->cachedStock($e['producto_a'], $e['origen']))->toBe('12.0000')
        ->and($invariant->cachedStock($e['producto_a'], $e['destino']))->toBe('4.0000');

    // 4. UNA TRANSFERENCIA A LA MISMA TIENDA no es una transferencia.
    expect(fn () => $service->dispatch(
        sourceStoreId: $e['origen'],
        destinationStoreId: $e['origen'],
        lines: [['product_id' => $e['producto_a'], 'quantity' => '1']],
        transferDate: new DateTimeImmutable('2026-11-05'),
    ))->toThrow(RuntimeException::class, 'misma tienda');

    expect(DB::table('transfers')->count())->toBe($antes['transfers'])
        ->and(DB::table('transfer_items')->count())->toBe($antes['items'])
        ->and(DB::table('transfer_item_lot_consumptions')->count())->toBe($antes['traza'])
        ->and((string) DB::table('inventory_lots')->where('id', $e['lote_a_viejo'])->value('available'))->toBe('6.0000');
});
