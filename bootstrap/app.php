<?php

use App\Console\Schedule\PosSchedule;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // El PERMISO de una ruta: `Route::middleware('permission:sales.read')`.
        //
        // El permiso se declara JUNTO a la ruta, y no en todos los
        // controladores: asi el inventario de lo que protege cada ruta se lee
        // de un vistazo.
        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // EL PLANIFICADOR. Lo que antes no existia y por eso no pasaba
        // nada: los comandos estaban, pero NADIE los llamaba.
        //
        // Y EL PLAN ESTA EN UNA CLASE (`PosSchedule`), no en un closure
        // aqui: el plan tiene que poder COMPROBARSE, y el planificador de
        // Laravel llena los eventos cuando arranca la consola, asi que un
        // test que lo mira en caliente depende del orden de los tests.
        (new PosSchedule)->define($schedule);
    })->create();
