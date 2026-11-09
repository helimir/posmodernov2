<?php

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

    // La CAJA: el punto de venta. La pantalla, la busqueda de productos y el
    // cobro. El cobro POSTea: el numero de documento y el de control se
    // reservan al cobrar, y una reserva que se quema por un doble envio es
    // un numero perdido (ADR-0001).
    Route::get('caja', [PosController::class, 'index'])->name('caja.index');
    Route::get('caja/productos', [PosController::class, 'products'])->name('caja.productos');
    Route::post('caja/cobrar', [PosController::class, 'checkout'])->name('caja.cobrar');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
