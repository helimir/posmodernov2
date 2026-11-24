<?php

namespace App\Http\Controllers;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Print\PrintAgentInventory;
use App\Print\PrintAgentRegistrationSecret;
use App\Print\PrintAgentToken;
use App\Print\PrintJobStateMachine;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El CONTRATO del agente LAN (design §C.3).
 *
 * Son cinco endpoints y el agente SIEMPRE inicia la conexion: registro,
 * latido, long-poll de trabajos, resultado y rotacion del token. El
 * servidor nunca alcanza al terminal, porque el terminal esta en una LAN
 * privada, detras de un NAT (design D12).
 *
 * El agente NO interpreta el ticket: recibe los bytes ya generados y solo
 * los escribe (design D11). Por eso el trabajo viaja con su hash, y por eso
 * el resultado se puede repetir sin efecto: un resultado repetido es un
 * no-op (idempotencia).
 *
 * La TIENDA se resuelve DESDE el `agent_id` autenticado, nunca desde el
 * cuerpo: un token de la tienda A no imprime en la tienda B.
 */
final class PrintAgentController extends Controller
{
    /**
     * El plazo del lease: el tiempo que el agente tiene para imprimir antes
     * de que su trabajo vuelva a la cola.
     */
    private const LEASE_SECONDS = 60;

    /**
     * El tope del long-poll: 30 segundos (design §C.3). El agente espera
     * como mucho eso antes de volver a preguntar.
     */
    public const ESPERA_MAXIMA_SEGUNDOS = 30;

    /**
     * Como se duerme el long-poll entre Consulta y consulta.
     */
    private const INTERVALO_ESPERA_MICROSEGUNDOS = 250000;

    /**
     * Cuantos trabajos se devuelven como maximo en una lectura.
     */
    private const LIMITE_POR_LECTURA = 10;

    /**
     * Cada cuanto debe latir el agente. Es un TERCIO del umbral de
     * desconexion (`config/print.php`): 60 s de umbral, tres intervalos de
     * 20 s.
     */
    private const POLL_AFTER_MS = 20000;

    public function __construct(
        private Connection $connection,
        private PrintJobStateMachine $stateMachine,
        private PrintAgentRegistrationSecret $secrets,
        private PrintAgentToken $tokens,
        private PrintAgentInventory $inventory,
    ) {}

    /**
     * El REGISTRO del agente.
     *
     * El agente declara su nombre, su tienda y su `instance_id`, y el
     * servidor le devuelve su identificador y su token EN CLARO, una sola vez:
     * en la tabla solo queda el hash.
     *
     * El `instance_id` es el identificador ESTABLE de la INSTALACION, y es lo
     * que hace que "una instalacion es una fila": si el agente se registra con
     * un `instance_id` que ya existe en la tienda, la fila anterior se
     * REEMPLAZA (token nuevo) y el token viejo deja de valer. Asi un terminal
     * REINSTALADO no deja vivo el token anterior.
     *
     * El nombre tambien es UNICO dentro de la tienda: si el agente ya existe
     * con ese nombre, la fila se reemplaza en vez de crear un duplicado (dos
     * cajas con el mismo nombre serian el mismo agente).
     *
     * El REGISTRO es el unico endpoint PUBLICO de la API (no hay token
     * todavia: el token es lo que el registro entrega), asi que exige el
     * SECRETO DE REGISTRO de la tienda en la cabecera `X-Registration-Secret`.
     * El interruptor `PRINT_LAN_AGENT_ENABLED` es la puerta maestra y el
     * secreto es la segunda capa: con el interruptor encendido pero sin
     * secreto, el registro sigue siendo imposible.
     */
    public function register(Request $request): JsonResponse
    {
        if ($deshabilitado = $this->deshabilitado()) {
            return $deshabilitado;
        }

        $name = trim((string) $request->input('name', ''));
        $storeId = $request->input('store_id');
        $instanceId = trim((string) $request->input('instance_id', ''));

        if ($name === '') {
            return $this->error('El agente debe declarar su nombre.');
        }

        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            return $this->error('El agente debe declarar la tienda a la que pertenece.');
        }

        $storeId = (int) $storeId;

        $tienda = $this->connection->table('stores')->where('id', $storeId)->first();

        if ($tienda === null) {
            return $this->error("La tienda {$storeId} no existe.");
        }

        // La SEGUNDA CAPA: el registro exige el secreto de SU tienda. Sin
        // secreto configurado la tienda NO PUEDE registrar agentes, y el
        // mensaje dice como configurarlo.
        if ($tienda->print_agent_registration_secret === null) {
            return $this->prohibido(
                "La tienda {$storeId} no tiene secreto de registro configurado: "
                ."ejecuta php artisan ops:print-agent-registration-secret {$storeId}."
            );
        }

        $secreto = trim((string) $request->header('X-Registration-Secret', ''));

        if (! $this->secrets->coincide($secreto, (string) $tienda->print_agent_registration_secret)) {
            return $this->prohibido('El secreto de registro no coincide con el de la tienda.');
        }

        // El `instance_id` se exige DESPUES del secreto: primero se sabe quien
        // pregunta, y despues se le dice que tiene que declarar quien es.
        if ($instanceId === '') {
            return $this->error(
                'El agente debe declarar su instance_id: es lo que permite reconocer un '
                .'terminal reinstalado y matar el token anterior en vez de dejar dos agentes '
                .'con el mismo nombre.'
            );
        }

        // La fila: primero por `instance_id` (la instalacion), y si no hay
        // ninguna, por nombre (el agente ya conocido).
        $agente = $this->connection->table('print_agents')
            ->where('store_id', $storeId)
            ->where('instance_id', $instanceId)
            ->first();

        if ($agente === null) {
            $agente = $this->connection->table('print_agents')
                ->where('store_id', $storeId)
                ->where('name', $name)
                ->first();
        }

        // La REVOCACION es definitiva: un agente revocado no se puede volver
        // a registrar con su `instance_id` hasta que un operador la levante.
        if ($agente !== null && $agente->revoked_at !== null) {
            return $this->prohibido(
                'El agente esta revocado: hay que levantar la revocacion del agente '
                .$agente->id.' antes de volver a registrarlo.'
            );
        }

        $token = $this->nuevoToken();
        $hash = $this->tokens->hash($token);

        if ($agente !== null) {
            // La fila se REEMPLAZA: el token anterior muere aqui mismo.
            $this->connection->table('print_agents')
                ->where('id', $agente->id)
                ->update([
                    'name' => $name,
                    'instance_id' => $instanceId,
                    'token_hash' => $hash,
                    // El token anterior NO sobrevive a un reemplazo: no hay
                    // ventana de gracia para un reinstalado.
                    'token_hash_previo' => null,
                    'token_previo_valido_hasta' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            $agentId = (int) $agente->id;
            $reemplazado = true;
        } else {
            $agentId = (int) $this->connection->table('print_agents')->insertGetId([
                'store_id' => $storeId,
                'name' => $name,
                'instance_id' => $instanceId,
                'token_hash' => $hash,
                'token_hash_previo' => null,
                'token_previo_valido_hasta' => null,
                'last_heartbeat_at' => null,
                'revoked_at' => null,
                'printers' => null,
                'capabilities' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reemplazado = false;
        }

        return response()->json([
            'status' => 'registered',
            'agent_id' => $agentId,
            'instance_id' => $instanceId,
            // Si la fila se REEMPLAZO, el servidor lo dice: el agente sabe
            // que su token anterior deja de valer.
            'replaced' => $reemplazado,
            // El token EN CLARO, una sola vez: la tabla solo guarda el hash.
            'token' => $token,
            'server_time' => now()->toIso8601String(),
            'poll_after_ms' => self::POLL_AFTER_MS,
        ], 201);
    }

    /**
     * El LATIDO del agente: dice que esta vivo, y DECLARA lo que tiene.
     *
     * El latido se guarda en un solo campo (`last_heartbeat_at`), que
     * tambien refresca el long-poll: un campo, dos puntos de refresco.
     *
     * Y el cuerpo del latido vale: el agente declara su INVENTARIO de
     * impresoras y sus CAPACIDADES, y el servidor los guarda y se los enseña.
     * Asi el desajuste entre la impresora declarada y la impresora real es
     * VISIBLE y no silencioso.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($deshabilitado = $this->deshabilitado()) {
            return $deshabilitado;
        }

        $agentId = $this->agentId($request);
        $storeId = $this->storeId($request);

        $agente = PrintAgent::query()->find($agentId);

        if ($agente === null) {
            return $this->error('El agente no existe.');
        }

        // El INVENTARIO y las CAPACIDADES solo se guardan si el agente los
        // DECLARA: un agente que no los declara no borra lo que habia dicho.
        if ($request->has('printers')) {
            $agente->printers = $this->inventario($request->input('printers'));
        }

        if ($request->has('capabilities')) {
            $agente->capabilities = $this->capacidades($request->input('capabilities'));
        }

        $agente->last_heartbeat_at = now();
        $agente->save();

        $inventario = $agente->printers ?? [];
        $capacidades = $agente->capabilities ?? [];

        // EL NUMERO HONESTO: los trabajos en cola que ESTE agente puede
        // tomar, ni mas ni menos. El contador y el reclamo tienen que
        // contar LO MISMO: si divergen, el agente espera trabajo que no va a
        // llegar, o toma trabajo que no le dijeron.
        $pendientes = $this->trabajosReclamables($storeId);

        return response()->json([
            'status' => 'ok',
            'pending_jobs' => count($pendientes),
            'poll_after_ms' => self::POLL_AFTER_MS,
            'server_time' => now()->toIso8601String(),
            // Lo que el servidor tiene guardado del agente: el agente ve su
            // propio inventario y sus propias capacidades.
            'printers' => $inventario,
            'capabilities' => $capacidades,
            // Los AVISOS: los destinos de la tienda que este inventario NO
            // cubre. NO son un rechazo: una impresora puede estar apagada en
            // ese momento, y rechazarlo seria perder un ticket que si se
            // puede imprimir.
            'warnings' => $this->destinosNoCubiertos($storeId, $inventario),
        ]);
    }

    /**
     * El LONG-POLL de trabajos.
     *
     * Devuelve los trabajos disponibles de la TIENDA DEL AGENTE y los
     * reclama: el que se lleva el trabajo es el agente, no el servidor. Con
     * `wait=0` devuelve al instante; el tope del long-poll son 30 segundos.
     */
    public function jobs(Request $request): JsonResponse
    {
        if ($deshabilitado = $this->deshabilitado()) {
            return $deshabilitado;
        }

        $agentId = $this->agentId($request);
        $storeId = $this->storeId($request);

        $wait = min(
            self::ESPERA_MAXIMA_SEGUNDOS,
            max(0, (int) $request->query('wait', 0))
        );

        $limite = min(50, max(1, (int) $request->query('limit', self::LIMITE_POR_LECTURA)));

        $limiteDeEspera = now()->addSeconds($wait);
        $trabajos = [];

        do {
            $trabajos = $this->reclamarTrabajos($storeId, $agentId, $limite);

            if ($trabajos !== [] || $wait === 0) {
                break;
            }

            // El long-poll espera y vuelve a preguntar: la espera se acorta
            // si queda menos tiempo que el tope.
            $restante = now()->diffInSeconds($limiteDeEspera);

            if ($restante <= 0) {
                break;
            }

            usleep(min(self::INTERVALO_ESPERA_MICROSEGUNDOS, (int) ($restante * 1000000)));
        } while (now()->lt($limiteDeEspera));

        // El long-poll tambien refresca el latido.
        $this->connection->table('print_agents')
            ->where('id', $agentId)
            ->update([
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'jobs' => $trabajos,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * El RESULTADO que informa el agente: el ticket salio, o fallo.
     *
     * Un resultado REPETIDO es un no-op: no falla y no cambia el estado.
     * El agente puede perder la respuesta y reenviar el resultado sin
     * miedo (idempotencia, design §C.3).
     */
    public function result(Request $request, int $id): JsonResponse
    {
        if ($deshabilitado = $this->deshabilitado()) {
            return $deshabilitado;
        }

        $agentId = $this->agentId($request);
        $storeId = $this->storeId($request);

        $estado = trim((string) $request->input('status', ''));

        $destino = match ($estado) {
            // El contrato dice `done`/`error`; el estado del trabajo es
            // `printed`/`failed`. Se admiten los dos nombres.
            'printed', 'done' => 'printed',
            'failed', 'error' => 'failed',
            default => null,
        };

        if ($destino === null) {
            return $this->error("El estado «{$estado}» no es valido: el agente informa «printed» o «failed».");
        }

        $trabajo = PrintJob::find($id);

        if ($trabajo === null || (int) $trabajo->store_id !== $storeId) {
            return $this->error("El trabajo de impresion {$id} no existe en esta tienda.");
        }

        // IDEMPOTENCIA: un resultado repetido devuelve el estado actual y no
        // cambia nada.
        if (in_array($trabajo->status, ['printed', 'failed'], true)) {
            return response()->json([
                'status' => 'ok',
                'job_status' => $trabajo->status,
            ]);
        }

        // El trabajo tiene que estar en las manos de ESTE agente.
        if (! in_array($trabajo->status, ['claimed', 'printing'], true)
            || (int) $trabajo->agent_id !== $agentId) {
            return $this->error("El trabajo de impresion {$id} no esta en manos de este agente.");
        }

        // Los bytes informados tienen que ser los del trabajo.
        $sha = $request->input('payload_sha256');

        if (is_string($sha) && $sha !== '' && $sha !== $trabajo->payload_sha256) {
            return $this->error("Los bytes informados no son los del trabajo {$id}.");
        }

        try {
            // El agente INFORMA del resultado: estaba imprimiendo.
            if ($trabajo->status === 'claimed') {
                $this->stateMachine->markPrinting($id);
            }

            if ($destino === 'printed') {
                $this->stateMachine->markPrinted($id);
            } else {
                $this->stateMachine->markFailed($id, (string) $request->input('error', ''));
            }
        } catch (RuntimeException $error) {
            return $this->error($error->getMessage());
        }

        return response()->json([
            'status' => 'ok',
            'job_status' => $destino,
        ]);
    }

    /**
     * La ROTACION del token.
     *
     * Genera un token nuevo y lo devuelve en claro. El token ANTERIOR no muere
     * en el acto: sigue valiendo hasta que se agote su VENTANA DE GRACIA
     * (`config/print.php`), para que el token nuevo le llegue al agente sin
     * dejar la caja muda mientras tanto.
     */
    public function rotate(Request $request): JsonResponse
    {
        if ($deshabilitado = $this->deshabilitado()) {
            return $deshabilitado;
        }

        $agentId = $this->agentId($request);

        // Si el cuerpo declara el token actual, tiene que ser el de la
        // cabecera.
        $actual = $request->input('current_token');

        if (is_string($actual) && $actual !== '') {
            $bearer = (string) $request->bearerToken();

            if (! hash_equals($actual, $bearer)) {
                return $this->prohibido('El token actual no coincide con el token de la cabecera.');
            }
        }

        $agente = PrintAgent::query()->find($agentId);

        if ($agente === null) {
            return $this->error('El agente no existe.');
        }

        $token = $this->nuevoToken();
        $grace = max(0, (int) config('print.token_grace_period_seconds'));
        $vence = now()->addSeconds($grace);

        $this->connection->table('print_agents')
            ->where('id', $agentId)
            ->update([
                // El hash vigente pasa a "previo" CON su fecha.
                'token_hash_previo' => $agente->token_hash,
                'token_previo_valido_hasta' => $vence,
                'token_hash' => $this->tokens->hash($token),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => 'ok',
            'agent_id' => $agentId,
            // El token EN CLARO, una sola vez.
            'token' => $token,
            // Cuando deja de valer el token anterior.
            'token_valid_until' => $vence->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Los TRABAJOS de la tienda que este agente puede tomar: los encolados
     * y con destino de tipo `lan_agent`.
     *
     * REGLA que fija este metodo: *el numero que se le dice al agente es el
     * numero de trabajos que ese agente puede tomar*.
     *
     * El unico trabajo que el agente toma es el de un destino `lan_agent`: el
     * `browser` no se escribe en bytes, y el `server_socket` los escribe el
     * servidor. Contarlos tambien era decirle al agente que tenia trabajo
     * que no iba a poder tomar nunca.
     *
     * Y EL INVENTARIO NO FILTRA: una impresora apagada no es un destino
     * descartado. El desajuste se AVISA (los `warnings` del latido), pero el
     * trabajo se toma igual: rechazarlo seria perder un ticket que si se
     * puede imprimir.
     *
     * @param  int|null  $limite  Cuantos se Devuelven; `null` es todos.
     * @return list<int> Los identificadores de los trabajos reclamables.
     */
    private function trabajosReclamables(int $storeId, ?int $limite = null): array
    {
        $consulta = $this->connection->table('print_jobs')
            ->join('print_targets', 'print_targets.id', '=', 'print_jobs.print_target_id')
            ->where('print_jobs.store_id', $storeId)
            ->where('print_jobs.status', 'queued')
            ->where('print_targets.transport', 'lan_agent')
            ->orderBy('print_jobs.created_at')
            ->orderBy('print_jobs.id');

        if ($limite !== null) {
            $consulta->limit($limite);
        }

        return $consulta->pluck('print_jobs.id')->all();
    }

    /**
     * El trabajo se RECLAMA con la maquina de estados, que es la unica via:
     * si otro agente lo toma primero, la maquina lo rechaza y aqui se
     * salta.
     *
     * @return list<array<string, mixed>>
     */
    private function reclamarTrabajos(int $storeId, int $agentId, int $limite): array
    {
        $ids = $this->trabajosReclamables($storeId, $limite);

        $trabajos = [];

        foreach ($ids as $id) {
            try {
                $this->stateMachine->claim((int) $id, $agentId, self::LEASE_SECONDS);
            } catch (RuntimeException) {
                // Otro agente lo toma primero: la maquina de estados decide.
                continue;
            }

            $trabajo = PrintJob::find((int) $id);

            if ($trabajo === null) {
                continue;
            }

            $trabajos[] = $this->presentar($trabajo);
        }

        return $trabajos;
    }

    /**
     * El trabajo, como lo ve el agente: los BYTES en base64 y su hash.
     *
     * @return array<string, mixed>
     */
    private function presentar(PrintJob $trabajo): array
    {
        $destino = $this->connection->table('print_targets')
            ->where('id', $trabajo->print_target_id)
            ->first();

        $impresora = $this->connection->table('printers')
            ->where('id', $destino->printer_id)
            ->first();

        return [
            'job_id' => (int) $trabajo->id,
            'target_id' => (int) $destino->id,
            'printer_key' => (string) $impresora->code,
            'payload_b64' => base64_encode((string) $trabajo->payload_bytes),
            'payload_sha256' => $trabajo->payload_sha256,
            // No hay columna de copias: un trabajo es un ticket.
            'copies' => 1,
            'charset' => (string) $destino->charset,
            'char_per_line' => (int) $destino->char_per_line,
        ];
    }

    /**
     * El INVENTARIO que declara el agente, normalizado.
     *
     * @return list<array<string, mixed>>
     */
    private function inventario(mixed $declarado): array
    {
        return $this->inventory->normalizar($declarado);
    }

    /**
     * Las CAPACIDADES que declara el agente.
     *
     * @return list<string>
     */
    private function capacidades(mixed $declarado): array
    {
        if (! is_array($declarado)) {
            return [];
        }

        $capacidades = [];

        foreach ($declarado as $capacidad) {
            if (! is_string($capacidad)) {
                continue;
            }

            $capacidad = trim($capacidad);

            if ($capacidad === '') {
                continue;
            }

            $capacidades[$capacidad] = true;
        }

        return array_keys($capacidades);
    }

    /**
     * Los DESTINOS de la tienda que el inventario del agente NO cubre.
     *
     * @param  list<array<string, mixed>>  $inventario
     * @return list<array<string, mixed>>
     */
    private function destinosNoCubiertos(int $storeId, array $inventario): array
    {
        return $this->inventory->destinosNoCubiertos($storeId, $inventario);
    }

    /**
     * El identificador del agente autenticado.
     */
    private function agentId(Request $request): int
    {
        return (int) $request->attributes->get('print_agent_id');
    }

    /**
     * La TIENDA del agente autenticado. Nunca la del cuerpo.
     */
    private function storeId(Request $request): int
    {
        return (int) $request->attributes->get('print_store_id');
    }

    /**
     * El token nuevo del agente: 256 bits de entropia, en hexadecimal.
     */
    private function nuevoToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * El interruptor del canal LAN: apagado hasta que el canal este
     * validado (`config/print.php`).
     */
    private function deshabilitado(): ?JsonResponse
    {
        if (filter_var(config('print.lan_agent_enabled'), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        return $this->prohibido('El canal del agente LAN esta deshabilitado.');
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['errorMsg' => $mensaje], 422);
    }

    private function prohibido(string $mensaje): JsonResponse
    {
        return response()->json(['errorMsg' => $mensaje], 403);
    }
}
