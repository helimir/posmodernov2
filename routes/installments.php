<?php

use App\Http\Controllers\Credit\InstallmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Las rutas de las cuotas
|--------------------------------------------------------------------------
|
| REGLA que fija este fichero: *el PERMISO de una ruta se declara JUNTO a la
| ruta, no dentro del controlador*. El inventario de "que protege cada ruta"
| se lee de un vistazo, aqui.
|
| Y LA REGLA que separa las dos cosas: *las PAGINAS son Inertia y las
| ESCRITURAS son JSON*. La pantalla se pide con un GET y se dibuja en el
| navegador; lo que se escribe (acordar el plan y cobrar la cuota) viaja por
| la API, con el mismo contrato de siempre (422 con `{"errorMsg": "..."}`).
|
| Y CADA ESCRITURA TIENE SU PERMISO, y no son los mismos: acordar el plan es
| DECIDIR como se paga, y cobrar la cuota es RECOGER el dinero. Quien cobra
| no acuerda planes, y quien acuerda planes no cobra.
*/

Route::middleware(['auth'])->group(function () {
    // LOS PLANES DE CUOTAS de la tienda: la pagina con el estado de cada
    // cuota y el boton de cobrar.
    Route::get('cuotas', [InstallmentController::class, 'index'])
        ->middleware('permission:installment.read')
        ->name('cuotas.index');

    // LAS CUOTAS DE UN CLIENTE: el EXTRACTO de sus planes, con la deuda que
    // dice el aging.
    Route::get('clientes/{customer}/cuotas', [InstallmentController::class, 'customer'])
        ->whereNumber('customer')
        ->middleware('permission:installment.read')
        ->name('clientes.cuotas');

    Route::prefix('api')->group(function () {
        // El PLAN DE CUOTAS de una venta a credito: el calendario con su
        // interes.
        Route::post('cuotas', [InstallmentController::class, 'store'])
            ->middleware('permission:installment.create')
            ->name('api.cuotas.store');

        // El COBRO de una cuota: el dinero entra a la caja, la cuota se marca
        // y el saldo del cliente baja.
        Route::post('cuotas/{cuota}/cobro', [InstallmentController::class, 'collect'])
            ->whereNumber('cuota')
            ->middleware('permission:installment.collect')
            ->name('api.cuotas.cobro');
    });
});
