<?php

use App\Http\Controllers\RoleAdministrationController;
use App\Http\Controllers\UserAdministrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La ADMINISTRACION de usuarios y roles
|--------------------------------------------------------------------------
|
| La pantalla que el sistema no tenia: quien es cada persona, que rol tiene
| y que puede ese rol.
|
| REGLA que fija este fichero: *el permiso de una ruta se declara JUNTO a la
| ruta*. Y LAS REGLAS SON DISTINTAS:
|
|  - VER (`user.read`, `role.read`) no es lo mismo que CAMBIAR
|    (`user.update`, `role.update`): quien puede ver el reparto de roles no
|    puede cambiarlo.
|  - Los CAMBIOS SE AUDITAN: cada cambio queda en `audit_logs` con su
|    usuario, su entidad y su IP.
|
*/

Route::middleware(['auth'])->group(function () {
    // Las PERSONAS: quien es, que rol tiene y en que tiendas opera.
    Route::get('usuarios', [UserAdministrationController::class, 'index'])
        ->middleware('permission:user.read')
        ->name('usuarios.index');

    Route::prefix('api')->group(function () {
        // El ROL de una persona: se asigna o se quita.
        Route::put('usuarios/{user}/rol', [UserAdministrationController::class, 'updateRole'])
            ->whereNumber('user')
            ->middleware('permission:user.update')
            ->name('api.usuarios.rol.update');
    });

    // Los ROLES: el reparto de permisos y el ambito de tiendas.
    Route::get('roles', [RoleAdministrationController::class, 'index'])
        ->middleware('permission:role.read')
        ->name('roles.index');
    Route::get('roles/{role}', [RoleAdministrationController::class, 'show'])
        ->whereNumber('role')
        ->middleware('permission:role.read')
        ->name('roles.show');

    Route::prefix('api')->group(function () {
        // Los PERMISOS de un rol: se ponen o se quitan.
        Route::put('roles/{role}/permisos', [RoleAdministrationController::class, 'updatePermissions'])
            ->whereNumber('role')
            ->middleware('permission:role.update')
            ->name('api.roles.permisos.update');

        // El AMBITO de tiendas de un rol: las tiendas donde puede operar.
        Route::put('roles/{role}/tiendas', [RoleAdministrationController::class, 'updateStores'])
            ->whereNumber('role')
            ->middleware('permission:role.update')
            ->name('api.roles.tiendas.update');
    });
});
