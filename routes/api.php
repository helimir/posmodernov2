<?php

use App\Http\Controllers\PrintAgentController;
use App\Http\Middleware\PrintAgentAuthenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| El contrato del AGENTE LAN (design §C.3)
|--------------------------------------------------------------------------
|
| Son cinco endpoints, y el agente SIEMPRE inicia la conexion: esta en una
| LAN privada, detras de un NAT, y el servidor nunca alcanza al terminal.
|
| El registro es el unico endpoint SIN token: es el arranque, y el token se
| pide en la respuesta, en claro, una sola vez. Los otros cuatro exigen
| `Authorization: Bearer <token>` y `X-Agent-Id`, y el servidor fuerza que
| las dos cosas digan lo mismo.
|
| Como el registro es PUBLICO, exige ademas el SECRETO DE REGISTRO de la
| tienda (`X-Registration-Secret`): lo emite
| `php artisan ops:print-agent-registration-secret {store}` y solo se
| guarda su hash. Es la segunda capa: el interruptor
| `PRINT_LAN_AGENT_ENABLED` es la puerta maestra.
|
*/

Route::post('/print-agents/register', [PrintAgentController::class, 'register']);

Route::middleware(PrintAgentAuthenticate::class)->group(function () {
    Route::post('/print-agents/heartbeat', [PrintAgentController::class, 'heartbeat']);
    Route::get('/print-agents/jobs', [PrintAgentController::class, 'jobs']);
    Route::post('/print-jobs/{id}/result', [PrintAgentController::class, 'result']);
    Route::post('/print-agents/rotate', [PrintAgentController::class, 'rotate']);
});
