<?php

use App\Http\Controllers\Purchasing\PurchaseController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Sales\SaleReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Las rutas de las compras, los proveedores y las devoluciones
|--------------------------------------------------------------------------
|
| REGLA que fija este fichero: *el PERMISO de una ruta se declara JUNTO a la
| ruta, no dentro del controlador*. El inventario de "que protege cada ruta"
| se lee de un vistazo, aqui.
|
| Y LA REGLA que separa las dos cosas: *las PAGINAS son Inertia y las
| ESCRITURAS son JSON*. La pantalla se pide con un GET y se dibuja en el
| navegador; lo que se escribe (el alta, la anulacion, la devolucion) viaja
| por la API, con el mismo contrato de siempre (422 con `{"errorMsg": "..."}`).
|
| Y CADA ESCRITURA TIENE SU PERMISO, y no son los mismos: quien registra la
| compra NO la anula (anular es una correccion de supervision), y el que
| anula no devuelve la mercaderia al proveedor (eso es una operacion fisica
| del mostrador).
*/

Route::middleware(['auth'])->group(function () {
    // LAS COMPRAS: la pagina, el alta, la anulacion y la devolucion al
    // proveedor.
    Route::get('compras', [PurchaseController::class, 'index'])
        ->middleware('permission:purchase.read')
        ->name('compras.index');

    // LOS PROVEEDORES: la pagina y el alta.
    Route::get('proveedores', [SupplierController::class, 'index'])
        ->middleware('permission:supplier.read')
        ->name('proveedores.index');

    // LAS DEVOLUCIONES DE MERCADERIA a los clientes: la pagina.
    //
    // Y NO ES LA ANULACION de la venta: la anulacion es fiscal y contable, y
    // la mercaderia NO vuelve al almacen. Aqui la mercaderia SI vuelve, y por
    // eso el stock se repone en el lote exacto del que salio.
    Route::get('ventas/devoluciones', [SaleReturnController::class, 'index'])
        ->middleware('permission:sale_return.read')
        ->name('ventas.devoluciones.index');

    Route::prefix('api')->group(function () {
        // La COMPRA: el alta, la anulacion y la devolucion al proveedor.
        Route::post('compras', [PurchaseController::class, 'store'])
            ->middleware('permission:purchase.create')
            ->name('api.compras.store');
        Route::post('compras/{purchase}/anular', [PurchaseController::class, 'cancel'])
            ->whereNumber('purchase')
            ->middleware('permission:purchase.cancel')
            ->name('api.compras.cancel');
        Route::post('compras/{purchase}/devolucion', [PurchaseController::class, 'returnToSupplier'])
            ->whereNumber('purchase')
            ->middleware('permission:purchase.return')
            ->name('api.compras.devolucion');

        // El PROVEEDOR: el alta.
        Route::post('proveedores', [SupplierController::class, 'store'])
            ->middleware('permission:supplier.create')
            ->name('api.proveedores.store');

        // La DEVOLUCION a un cliente: las lineas que se pueden devolver de una
        // venta, y el alta de la devolucion.
        Route::get('ventas/{sale}/devolucion', [SaleReturnController::class, 'lines'])
            ->whereNumber('sale')
            ->middleware('permission:sale_return.read')
            ->name('api.ventas.devolucion.lines');
        Route::post('ventas/{sale}/devolucion', [SaleReturnController::class, 'store'])
            ->whereNumber('sale')
            ->middleware('permission:sale_return.create')
            ->name('api.ventas.devolucion.store');
    });
});
