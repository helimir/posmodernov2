<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La AUTENTICACION del agente LAN.
 *
 * El agente se identifica con DOS cosas: el token (`Authorization: Bearer`)
 * y su identificador (`X-Agent-Id`). El servidor FUERZA que las dos digan
 * lo mismo: un token no puede hablar en nombre de otro agente.
 *
 * El token se guarda SOLO como hash, asi que la busqueda es por el hash del
 * token recibido y la comparacion final es en TIEMPO CONSTANTE
 * (`hash_equals`). El token en claro nunca se persiste ni se compara
 * directamente.
 *
 * La TIENDA se resuelve DESDE el agente autenticado, nunca desde el cuerpo
 * de la peticion: un `store_id` en el cuerpo se ignora. Un token de la
 * tienda A no puede imprimir en la tienda B (design D13).
 */
final class PrintAgentAuthenticate
{
    public function __construct(private Connection $connection) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->noAutorizado('El agente no presenta su token.');
        }

        $hash = hash('sha256', $token);

        $agente = $this->connection->table('print_agents')
            ->where('token_hash', $hash)
            ->first();

        if ($agente === null) {
            return $this->noAutorizado('El token del agente no es valido.');
        }

        // La comparacion es en tiempo constante: el hash recibido se compara
        // con el guardado, no con `==`.
        if (! hash_equals((string) $agente->token_hash, $hash)) {
            return $this->noAutorizado('El token del agente no es valido.');
        }

        if (! in_array($agente->is_active, [true, 1, '1', 't', 'true'], true)) {
            return $this->noAutorizado('El agente esta desactivado.');
        }

        // El `X-Agent-Id` TIENE que ser el del agente autenticado.
        $agentId = trim((string) $request->header('X-Agent-Id', ''));

        if ($agentId === '' || ! ctype_digit($agentId)) {
            return $this->prohibido('La cabecera X-Agent-Id no identifica a un agente.');
        }

        if ((int) $agentId !== (int) $agente->id) {
            return $this->prohibido('El token no pertenece al agente de la cabecera X-Agent-Id.');
        }

        // El `agent_id` del cuerpo (design §C.3) tampoco puede contradecir al
        // de la cabecera.
        $agentIdDelCuerpo = $request->input('agent_id');

        if ($agentIdDelCuerpo !== null && (int) $agentIdDelCuerpo !== (int) $agente->id) {
            return $this->prohibido('El agent_id del cuerpo no coincide con el agente autenticado.');
        }

        // La TIENDA sale del AGENTE. Un `store_id` en el cuerpo se ignora.
        $request->attributes->set('print_agent_id', (int) $agente->id);
        $request->attributes->set('print_store_id', (int) $agente->store_id);

        return $next($request);
    }

    private function noAutorizado(string $mensaje): JsonResponse
    {
        return response()->json(['errorMsg' => $mensaje], 401);
    }

    private function prohibido(string $mensaje): JsonResponse
    {
        return response()->json(['errorMsg' => $mensaje], 403);
    }
}
