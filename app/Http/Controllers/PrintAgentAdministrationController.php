<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Print\PrintAgentToken;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La ADMINISTRACION del agente LAN: la REVOCACION y su levantada.
 *
 * REGLA que fija esta clase: *la revocacion de un agente es DEFINITIVA, y
 * solo un operador la levanta*.
 *
 * `is_active` es un INTERRUPTOR: se puede apagar y volver a encender. La
 * revocacion es otra cosa: es una SENTENCE. Un agente revocado no habla
 * aunque su token sea correcto, y no se puede volver a registrar con su
 * `instance_id` hasta que alguien levante la revocacion a mano. Asi el token
 * de un agente comprometido no vuelve a la vida por accidente.
 *
 * Y TODO CAMBIO SE AUDITA: la revocacion y su levantada quedan escritas,
 * con quien lo hizo y desde donde. Un token que se revoca y luego se levanta
 * es un hecho que hay que poder revisar despues.
 *
 * Y EL AGENTE ES POR TIENDA: solo se revoca el agente de la tienda activa,
 * que es la misma que usa la caja.
 */
final class PrintAgentAdministrationController extends Controller
{
    /**
     * La ACCION de la auditoria: se ha revocado un agente.
     */
    private const ACCION_REVOCADO = 'print_agent.revoked';

    /**
     * La ACCION de la auditoria: se ha levantado la revocacion.
     */
    private const ACCION_RESTAURADO = 'print_agent.restored';

    /**
     * La ENTIDAD de la auditoria: el agente.
     */
    private const ENTIDAD_AGENTE = 'print_agent';

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditWriter $audit,
        private readonly PrintAgentToken $tokens,
    ) {}

    /**
     * La REVOCACION de un agente.
     *
     * A partir de aqui el agente no habla: ni con su token, ni con el token
     * anterior dentro de la ventana de gracia. La revocacion es definitiva
     * hasta que un operador la levante.
     */
    public function revoke(Request $request, int $agent): JsonResponse
    {
        $agente = $this->agenteDeLaTienda($request, $agent);

        if ($agente === null) {
            return response()->json([
                'errorMsg' => 'El agente no existe en la tienda activa.',
            ], 422);
        }

        if ($agente->revoked_at !== null) {
            return response()->json([
                'errorMsg' => 'El agente ya esta revocado.',
            ], 422);
        }

        $this->connection->table('print_agents')
            ->where('id', (int) $agente->id)
            ->update([
                'revoked_at' => now(),
                'is_active' => false,
                'updated_at' => now(),
            ]);

        $this->audit->recordFor(
            $request,
            self::ACCION_REVOCADO,
            self::ENTIDAD_AGENTE,
            (int) $agente->id,
            $this->contexto($agente),
        );

        return response()->json([
            'status' => 'revoked',
            'agent_id' => (int) $agente->id,
            'revoked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * La LEVANTADA de la revocacion.
     *
     * Y NO RESUCITA EL TOKEN: al levantar la revocacion, el token que
     * hubiera queda invalidado. Si el agente se revoco porque su token pudo
     * filtrarse, levantarla no puede devolver a la vida ese token. El agente
     * se vuelve a registrar (con su `instance_id`, que ya conoce) y recibe un
     * token nuevo.
     */
    public function restore(Request $request, int $agent): JsonResponse
    {
        $agente = $this->agenteDeLaTienda($request, $agent);

        if ($agente === null) {
            return response()->json([
                'errorMsg' => 'El agente no existe en la tienda activa.',
            ], 422);
        }

        if ($agente->revoked_at === null) {
            return response()->json([
                'errorMsg' => 'El agente no esta revocado.',
            ], 422);
        }

        // Un hash de un secreto que NADIE conoce: invalida el token sin
        // dejar un valor con el que alguien pueda autenticarse.
        $tokenMuerto = $this->tokens->hash(bin2hex(random_bytes(32)));

        $this->connection->table('print_agents')
            ->where('id', (int) $agente->id)
            ->update([
                'revoked_at' => null,
                'is_active' => true,
                'token_hash' => $tokenMuerto,
                'token_hash_previo' => null,
                'token_previo_valido_hasta' => null,
                'updated_at' => now(),
            ]);

        $this->audit->recordFor(
            $request,
            self::ACCION_RESTAURADO,
            self::ENTIDAD_AGENTE,
            (int) $agente->id,
            $this->contexto($agente),
        );

        return response()->json([
            'status' => 'restored',
            'agent_id' => (int) $agente->id,
            'revoked_at' => null,
        ]);
    }

    /**
     * El AGENTE de la tienda activa, o ninguno.
     */
    private function agenteDeLaTienda(Request $request, int $agent): ?object
    {
        $tienda = $this->tienda($request);

        return $this->connection->table('print_agents')
            ->where('id', $agent)
            ->where('store_id', (int) $tienda->id)
            ->first();
    }

    /**
     * La TIENDA del contexto: la misma que usa la caja.
     */
    private function tienda(Request $request): Store
    {
        $context = new PosContext($this->connection, $request->user()?->id);

        try {
            return $context->store();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }
    }

    /**
     * El CONTEXTO de la auditoria: que agente se toco.
     *
     * @return array<string, mixed>
     */
    private function contexto(object $agente): array
    {
        return [
            'name' => (string) $agente->name,
            'instance_id' => $agente->instance_id === null ? null : (string) $agente->instance_id,
            'store_id' => (int) $agente->store_id,
        ];
    }
}
