<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\StoreController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // La CONFIGURACION de la TIENDA: los umbrales que la tienda decide. No
    // es el perfil de la persona (eso son los tres de arriba): es el
    // COMPORTAMIENTO de la tienda, y por eso tiene su propio permiso.
    Route::get('settings/store', [StoreController::class, 'edit'])
        ->middleware('permission:store.read')
        ->name('store.edit');

    Route::put('settings/store', [StoreController::class, 'update'])
        ->middleware('permission:store.update')
        ->name('store.update');
});
