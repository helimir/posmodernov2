<?php

namespace App\Console\Commands;

use App\Print\PrintAgentRegistrationSecret;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;

/**
 * El comando que EMITE (y rota) el secreto de registro del agente LAN de una
 * tienda.
 *
 * REGLA que fija: *el secreto de registro se ve UNA SOLA VEZ y en la base de
 * datos solo queda su HASH*.
 *
 * El registro del agente es el unico endpoint PUBLICO de la API del agente
 * LAN (no hay token todavia: el token es lo que el registro entrega), asi que
 * necesita una segunda defensa: el secreto de la tienda, que solo conoce
 * quien instala el agente.
 *
 * Este comando es el UNICO camino para emitirlo, y cada ejecucion ROTA el
 * secreto anterior: el que se entrego a un agente deja de valer en cuanto se
 * emite otro. El comando NUNCA escribe el secreto en claro: escribe su hash
 * SHA-256, que es lo unico que admite la columna.
 */
class PrintAgentRegistrationSecretCommand extends Command
{
    protected $signature = 'ops:print-agent-registration-secret {store : El identificador de la tienda}';

    protected $description = 'Emite (o rota) el secreto de registro del agente LAN de una tienda.';

    public function __construct(
        private readonly Connection $connection,
        private readonly PrintAgentRegistrationSecret $secrets,
    ) {
        parent::__construct();
    }

    /**
     * Emite el secreto de registro de la tienda y guarda su hash.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El secreto se genera con 256 bits de entropia criptografica y se
     *     muestra EN CLARO, UNA SOLA VEZ, con un aviso explicito de que no se
     *     vuelve a mostrar: es lo que hay que entregar a quien instala el
     *     agente.
     *  2. En la base de datos solo se escribe el HASH del secreto. El
     *     secreto en claro no se persiste NUNCA.
     *  3. Cada ejecucion ROTA el secreto: el anterior deja de valer.
     *  4. Una tienda que no existe es un ERROR con codigo de salida distinto
     *     de cero: no se inventa una tienda ni se emite un secreto que nadie
     *     podria usar.
     */
    public function handle(): int
    {
        $storeId = filter_var($this->argument('store'), FILTER_VALIDATE_INT);

        if ($storeId === false || $storeId <= 0) {
            $this->error('El identificador de la tienda no es valido.');

            return self::FAILURE;
        }

        $tienda = $this->connection->table('stores')->where('id', $storeId)->first();

        if ($tienda === null) {
            $this->error("La tienda {$storeId} no existe.");

            return self::FAILURE;
        }

        $secreto = $this->secrets->generate();

        // En la base de datos SOLO el hash: el secreto en claro no se persiste.
        $this->connection->table('stores')->where('id', $storeId)->update([
            'print_agent_registration_secret' => $this->secrets->hash($secreto),
            'updated_at' => now(),
        ]);

        $this->info("Se ha emitido el secreto de registro de la tienda {$storeId} ({$tienda->name}).");
        $this->warn('ATENCION: este secreto se muestra UNA SOLA VEZ. No se vuelve a mostrar.');
        $this->line('Secreto de registro: '.$secreto);
        $this->line('Entregalo a quien instala el agente LAN de esta tienda y guardalo fuera del servidor.');

        return self::SUCCESS;
    }
}
