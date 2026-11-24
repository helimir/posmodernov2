<?php

use App\Http\Controllers\PrintAdministrationController;
use App\Http\Controllers\PrintAgentAdministrationController;
use App\Http\Controllers\PrintJobQueueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La ADMINISTRACION de la impresion
|--------------------------------------------------------------------------
|
| El catalogo de impresoras y los destinos de impresion de la tienda.
|
| REGLA que fija este fichero: *el permiso de una ruta se declara JUNTO a la
| ruta*, y las reglas son DISTINTAS:
|
|  - VER (`printing.read`) no es lo mismo que CREAR (`printing.create`), ni
|    que CAMBIAR (`printing.update`), ni que PROBAR (`printing.test`): quien
|    puede ver la configuracion de las impresoras no puede cambiarla;
|  - los CAMBIOS SE AUDITAN: cada cambio queda en `audit_logs` con su
|    usuario, su entidad y su IP.
|
| Y LAS PAGINAS SON INERTIA y las ESCRITURAS SON JSON, como en el resto del
| repositorio: la pantalla se pide con un GET y lo que se escribe viaja por la
| API, con el mismo contrato de siempre (422 con `{"errorMsg": "..."}`).
*/

Route::middleware(['auth'])->group(function () {
    // El CATALOGO de impresoras: la identidad de los modelos.
    Route::get('impresion/impresoras', [PrintAdministrationController::class, 'printers'])
        ->middleware('permission:printing.read')
        ->name('impresion.impresoras.index');

    // Los DESTINOS de impresion de la tienda activa.
    Route::get('impresion/destinos', [PrintAdministrationController::class, 'targets'])
        ->middleware('permission:printing.read')
        ->name('impresion.destinos.index');

    // La COLA de trabajos de impresion de la tienda activa.
    Route::get('impresion/trabajos', [PrintJobQueueController::class, 'jobs'])
        ->middleware('permission:printing.read')
        ->name('impresion.trabajos.index');

    // Los AGENTES LAN de la tienda activa: su estado, su inventario y la
    // REVOCACION (que es definitiva y solo un operador la levanta).
    Route::get('impresion/agentes', [PrintAgentAdministrationController::class, 'agents'])
        ->middleware('permission:printing.read')
        ->name('impresion.agentes.index');

    Route::prefix('api')->group(function () {
        // El CATALOGO de impresoras, en JSON.
        Route::get('impresion/impresoras', [PrintAdministrationController::class, 'indexPrinters'])
            ->middleware('permission:printing.read')
            ->name('api.impresion.impresoras.index');

        Route::post('impresion/impresoras', [PrintAdministrationController::class, 'storePrinter'])
            ->middleware('permission:printing.create')
            ->name('api.impresion.impresoras.store');

        Route::put('impresion/impresoras/{printer}', [PrintAdministrationController::class, 'updatePrinter'])
            ->whereNumber('printer')
            ->middleware('permission:printing.update')
            ->name('api.impresion.impresoras.update');

        // Los DESTINOS de impresion, en JSON.
        Route::get('impresion/destinos', [PrintAdministrationController::class, 'indexTargets'])
            ->middleware('permission:printing.read')
            ->name('api.impresion.destinos.index');

        Route::post('impresion/destinos', [PrintAdministrationController::class, 'storeTarget'])
            ->middleware('permission:printing.create')
            ->name('api.impresion.destinos.store');

        Route::put('impresion/destinos/{target}', [PrintAdministrationController::class, 'updateTarget'])
            ->whereNumber('target')
            ->middleware('permission:printing.update')
            ->name('api.impresion.destinos.update');

        // La PRUEBA de la conexion: abre la conexion y la cierra, sin
        // escribir un ticket de verdad.
        Route::post('impresion/destinos/{target}/probar', [PrintAdministrationController::class, 'testConnection'])
            ->whereNumber('target')
            ->middleware('permission:printing.test')
            ->name('api.impresion.destinos.probar');

        // La COLA de trabajos de impresion, en JSON.
        Route::get('print-jobs', [PrintJobQueueController::class, 'index'])
            ->middleware('permission:printing.read')
            ->name('api.print-jobs.index');

        // El REINTENTO: un trabajo fallido vuelve a la cola, con un intento
        // mas y con los MISMOS bytes.
        Route::post('print-jobs/{id}/reintentar', [PrintJobQueueController::class, 'retry'])
            ->whereNumber('id')
            ->middleware('permission:printing.retry')
            ->name('api.print-jobs.retry');

        // La REIMPRESION: los MISMOS bytes congelados, otra vez. Y CERO
        // reservas: reimprimir no es reemitir.
        Route::post('print-jobs/{id}/reimprimir', [PrintJobQueueController::class, 'reprint'])
            ->whereNumber('id')
            ->middleware('permission:printing.reprint')
            ->name('api.print-jobs.reprint');

        // La REVOCACION del agente LAN: es DEFINITIVA, y solo un operador la
        // levanta. Un agente revocado no habla, aunque su token sea correcto,
        // y no se puede volver a registrar con su `instance_id` hasta que la
        // revocacion se levante a mano.
        Route::post('print-agents/{agent}/revoke', [PrintAgentAdministrationController::class, 'revoke'])
            ->whereNumber('agent')
            ->middleware('permission:printing.update')
            ->name('api.print-agents.revoke');

        Route::post('print-agents/{agent}/restore', [PrintAgentAdministrationController::class, 'restore'])
            ->whereNumber('agent')
            ->middleware('permission:printing.update')
            ->name('api.print-agents.restore');
    });
});
