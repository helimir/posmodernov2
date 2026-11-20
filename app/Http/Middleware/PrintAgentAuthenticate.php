<?php

namespace App\Http\Middleware;

use App\Models\PrintAgent;
use App\Print\PrintAgentToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La AUTENTICACION del agente LAN.
 *
 * REGLA que fija este middleware: *el token del agente se verifica con
 * `password_verify` contra el hash de ESE agente, y un agente revocado no
 * habla aunque su token sea correcto*.
 *
 * El agente se identifica con DOS cosas: el token (`Authorization: Bearer`) y
 * su identificador (`X-Agent-Id`). El servidor FUERZA que las dos digan lo
 * mismo: un token no puede hablar en nombre de otro agente.
 *
 * Y LA BUSQUEDA ES POR EL IDENTIFICADOR, NO POR EL HASH: argon2id lleva sal,
 * asi que dos agentes nunca comparten hash y no se puede indexar. La fila se
 * localiza con `X-Agent-Id` y el token se verifica contra SU hash, con
 * `password_verify`, que compara en tiempo constante.
 *
 * Y HAY UNA VENTANA DE GRACIA: al rotar el token, el ANTERIOR sigue valiendo
 * un rato (`token_hash_previo` y `token_previo_valido_hasta`), para que el
 * token nuevo le llegue al agente sin dejar la caja muda.
 *
 * La TIENDA se resuelve DESDE el agente autenticado, nunca desde el cuerpo de
 * la peticion: un `store_id` en el cuerpo se ignora. Un token de la tienda A
 * no puede imprimir en la tienda B (design D13).
 */
final class PrintAgentAuthenticate
{
    public function __construct(private readonly PrintAgentToken $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->noAutorizado('El agente no presenta su token.');
        }

        // La CABECERA dice QUE agente es. El token dice SI es el.
        $agentId = trim((string) $request->header('X-Agent-Id', ''));

        if ($agentId === '' || ! ctype_digit($agentId)) {
            return $this->prohibido('La cabecera X-Agent-Id no identifica a un agente.');
        }

        $agente = PrintAgent::query()->find((int) $agentId);

        if ($agente === null) {
            return $this->noAutorizado('El token del agente no es valido.');
        }

        // La REVOCACION es DEFINITIVA: ni el token ni la ventana de gracia la
        // levantan. Solo la levanta un operador.
        if ($agente->revoked_at !== null) {
            return $this->noAutorizado('El agente esta revocado.');
        }

        if (! $agente->is_active) {
            return $this->noAutorizado('El agente esta desactivado.');
        }

        // El token: el VIGENTE, o el ANTERIOR dentro de su ventana de gracia.
        //
        // El token anterior SOLO se comprueba cuando el vigente no vale: la
        // comprobacion de un hash de contrasena es cara, y en el camino normal
        // no hace falta pagarla dos veces.
        $valido = $this->tokens->coincide($token, $agente->token_hash);

        if (! $valido) {
            $valido = $this->tokens->dentroDeLaVentana(
                $token,
                $agente->token_hash_previo,
                $agente->token_previo_valido_hasta,
            );
        }

        if (! $valido) {
            return $this->noAutorizado('El token del agente no es valido.');
        }

        // El `agent_id` del cuerpo (design §C.3) tampoco puede contradecir al
        // de la cabecera.
        $agentIdDelCuerpo = $request->input('agent_id');

        if ($agentIdDelCuerpo !== null && (int) $agentIdDelCuerpo !== (int) $agente->id) {
            return $this->prohibido('El agent_id del cuerpo no coincide con el agente autenticado.');
        }

        // El `instance_id` del cuerpo es la identidad de la INSTALACION: si
        // contradice al del agente autenticado, la peticion no es de esta
        // instalacion.
        $instanceIdDelCuerpo = trim((string) $request->input('instance_id', ''));

        if ($instanceIdDelCuerpo !== '' && $instanceIdDelCuerpo !== (string) $agente->instance_id) {
            return $this->prohibido('El instance_id del cuerpo no coincide con el agente autenticado.');
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
