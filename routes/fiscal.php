<?php

use App\Http\Controllers\FiscalAdministrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La ADMINISTRACION FISCAL
|--------------------------------------------------------------------------
|
| Las series, los perfiles y los proveedores fiscales: lo que hoy solo
| existe en los sembradores.
|
 | REGLA que fija este fichero: *el permiso de una ruta se declara JUNTO a la
 | ruta*, y las reglas son DISTINTAS:
 |
 |  - VER (`fiscal.read`) no es lo mismo que CREAR (`fiscal.create`) ni que
 |    CAMBIAR (`fiscal.update`): quien puede ver la numeracion declarada de
 |    una tienda no puede declararla;
 |  - las PAGINAS SON INERTIA y las ESCRITURAS SON JSON, como en el resto del
 |    repositorio: la pantalla se pide con un GET y lo que se escribe viaja por
 |    la API, con el mismo contrato de siempre (422 con `{"errorMsg": "..."}`);
 |  - las SERIES son POR TIENDA, y el ambito de tiendas lo resuelve
 |    `PosContext`: las series que se ven son las de las tiendas donde el
 |    usuario puede operar.
 |
 | Y EL PERMISO ES EL DEL MODULO, COMO EL DE LA IMPRESION: la administracion
 | fiscal es UN area con tres pantallas, y el area tiene su modulo (`fiscal`)
 | y sus tres acciones. El catalogo entero usa la forma `{modulo}.{accion}`.
 |
 | Y LO QUE ESTA RUTA NO HACE: no numera. El correlativo lo reserva el
 | dominio (`NumberSeries`, con `UPDATE ... RETURNING`), y el alta de una serie
 | no consume ningun numero.
 */

Route::middleware(['auth'])->group(function () {
    // Las SERIES fiscales: la numeracion declarada de las tiendas.
    Route::get('fiscal/series', [FiscalAdministrationController::class, 'series'])
        ->middleware('permission:fiscal.read')
        ->name('fiscal.series.index');

    // Los PERFILES fiscales: los regimenes declarados.
    Route::get('fiscal/perfiles', [FiscalAdministrationController::class, 'profiles'])
        ->middleware('permission:fiscal.read')
        ->name('fiscal.profiles.index');

    // Los PROVEEDORES fiscales: los canales de emision.
    Route::get('fiscal/proveedores', [FiscalAdministrationController::class, 'providers'])
        ->middleware('permission:fiscal.read')
        ->name('fiscal.providers.index');

    Route::prefix('api')->group(function () {
        // Las SERIES, en JSON.
        Route::get('fiscal/series', [FiscalAdministrationController::class, 'indexSeries'])
            ->middleware('permission:fiscal.read')
            ->name('api.fiscal.series.index');

        Route::post('fiscal/series', [FiscalAdministrationController::class, 'storeSeries'])
            ->middleware('permission:fiscal.create')
            ->name('api.fiscal.series.store');

        Route::put('fiscal/series/{series}', [FiscalAdministrationController::class, 'updateSeries'])
            ->whereNumber('series')
            ->middleware('permission:fiscal.update')
            ->name('api.fiscal.series.update');

        // Los PERFILES, en JSON.
        Route::get('fiscal/profiles', [FiscalAdministrationController::class, 'indexProfiles'])
            ->middleware('permission:fiscal.read')
            ->name('api.fiscal.profiles.index');

        Route::post('fiscal/profiles', [FiscalAdministrationController::class, 'storeProfile'])
            ->middleware('permission:fiscal.create')
            ->name('api.fiscal.profiles.store');

        Route::put('fiscal/profiles/{profile}', [FiscalAdministrationController::class, 'updateProfile'])
            ->whereNumber('profile')
            ->middleware('permission:fiscal.update')
            ->name('api.fiscal.profiles.update');

        // Los PROVEEDORES, en JSON.
        Route::get('fiscal/proveedores', [FiscalAdministrationController::class, 'indexProviders'])
            ->middleware('permission:fiscal.read')
            ->name('api.fiscal.providers.index');

        Route::post('fiscal/proveedores', [FiscalAdministrationController::class, 'storeProvider'])
            ->middleware('permission:fiscal.create')
            ->name('api.fiscal.providers.store');

        Route::put('fiscal/proveedores/{provider}', [FiscalAdministrationController::class, 'updateProvider'])
            ->whereNumber('provider')
            ->middleware('permission:fiscal.update')
            ->name('api.fiscal.providers.update');
    });
});
