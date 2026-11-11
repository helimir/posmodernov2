<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Las ventas: la costura en el navegador. La lista y el detalle.
    Route::get('ventas', [SalesController::class, 'index'])->name('ventas.index');
    Route::get('ventas/{sale}', [SalesController::class, 'show'])
        ->whereNumber('sale')
        ->name('ventas.show');

    // La CAJA: el punto de venta. La pantalla, la busqueda de productos, el
    // presupuesto y el cobro. El cobro POSTea: el numero de documento y el de
    // control se reservan al cobrar, y una reserva que se quema por un doble
    // envio es un numero perdido (ADR-0001).
    //
    // El PRESUPUESTO tambien POSTea y NO cobra: devuelve el total que
    // calcula el motor de impuestos, para que la pantalla lo enseñe sin
    // replicar la aritmetica fiscal en el navegador.
    Route::get('caja', [PosController::class, 'index'])->name('caja.index');
    Route::get('caja/productos', [PosController::class, 'products'])->name('caja.productos');
    Route::post('caja/presupuesto', [PosController::class, 'budget'])->name('caja.presupuesto');
    Route::post('caja/cobrar', [PosController::class, 'checkout'])->name('caja.cobrar');

    // Los CLIENTES y su CREDITO: las PAGINAS y la API.
    //
    // LA REGLA que separa las dos: *las PAGINAS son Inertia y las
    // ESCRITURAS son JSON*. La pantalla se pide con un GET y se dibuja en el
    // navegador; lo que se escribe (el alta, la edicion, el cobro) viaja por
    // la API, y la UI ESCRIBE con `fetch` y REDIRIGE con `router.visit`.
    //
    // Por eso las dos cosas NO pueden vivir en la misma ruta: `GET
    // /clientes/{id}` es la PANTALLA de la ficha y `GET
    // /api/clientes/{id}` es su JSON. Una ruta que sirve las dos no
    // decide nunca que responder.
    //
    // El ALTA y el COBRO van por POST, y la EDICION por PUT: escriben el
    // cliente y su ficha de tienda, y el cobro escribe un movimiento en el
    // libro del cliente. Un cobro que se repitiera por un doble envio
    // cobraria dos veces, asi que el movimiento es un hecho, no una pantalla.
    Route::get('clientes', [CustomerController::class, 'listado'])->name('clientes.index');
    Route::get('clientes/nuevo', [CustomerController::class, 'alta'])->name('clientes.create');
    Route::get('clientes/{customer}/editar', [CustomerController::class, 'edicion'])
        ->whereNumber('customer')
        ->name('clientes.edit');
    Route::get('clientes/{customer}', [CustomerController::class, 'ficha'])
        ->whereNumber('customer')
        ->name('clientes.show');

    // La API de clientes: la lista, la ficha, el alta, la edicion y el cobro.
    // Vive en `/api` y conserva su contrato: los mismos campos y los mismos
    // 422 con `{"errorMsg": "..."}`.
    Route::prefix('api')->group(function () {
        Route::get('clientes', [CustomerController::class, 'index'])->name('api.clientes.index');
        Route::post('clientes', [CustomerController::class, 'store'])->name('api.clientes.store');
        Route::get('clientes/{customer}', [CustomerController::class, 'show'])
            ->whereNumber('customer')
            ->name('api.clientes.show');
        Route::put('clientes/{customer}', [CustomerController::class, 'update'])
            ->whereNumber('customer')
            ->name('api.clientes.update');
        Route::post('clientes/{customer}/cobros', [CustomerController::class, 'cobro'])
            ->whereNumber('customer')
            ->name('api.clientes.cobros.store');

        // El SELECTOR DE CLIENTES de la caja: los clientes con ficha en la
        // tienda activa, con su saldo derivado.
        Route::get('caja/clientes', [PosController::class, 'clientes'])->name('api.caja.clientes');
    });

    // El CATALOGO: el maestro de productos y su precio por tienda. El
    // listado, el alta, la ficha y la API de precios.
    //
    // El ALTA y la EDICION van por POST y PUT: escriben el maestro y la
    // ficha de la tienda activa con Eloquent y con validacion, como
    // cualquier CRUD de Laravel.
    Route::get('catalogo', [CatalogController::class, 'index'])->name('catalogo.index');
    Route::get('catalogo/nuevo', [CatalogController::class, 'create'])->name('catalogo.create');
    Route::post('catalogo', [CatalogController::class, 'store'])->name('catalogo.store');
    Route::get('catalogo/{product}', [CatalogController::class, 'show'])
        ->whereNumber('product')
        ->name('catalogo.show');
    Route::put('catalogo/{product}', [CatalogController::class, 'update'])
        ->whereNumber('product')
        ->name('catalogo.update');

    // El PRECIO POR TIENDA: la API que usa la caja y que edita el precio de
    // una tienda concreta.
    Route::get('catalogo/{product}/precios', [CatalogController::class, 'prices'])
        ->whereNumber('product')
        ->name('catalogo.precios');
    Route::put('catalogo/{product}/precios', [CatalogController::class, 'updatePrices'])
        ->whereNumber('product')
        ->name('catalogo.precios.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
