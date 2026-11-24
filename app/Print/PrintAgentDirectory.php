<?php

namespace App\Print;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * El DIRECTORIO de los agentes LAN de una tienda.
 *
 * REGLA que fija esta clase: *el agente se enseena siempre igual, y el
 * desajuste de su inventario se avisa con la MISMA regla que el latido*.
 *
 * El agente aparece en DOS pantallas (la de destinos y la de agentes) y en
 * el latido, y los tres tienen que decir lo mismo: si el agente se cuenta
 * dos veces, el desajuste del inventario se avisa dos veces, y una pantalla
 * que avisa de algo que otra no avisa miente por omision.
 *
 * Y EL INVENTARIO LO DECLARA EL AGENTE: lo dice en su latido, el servidor lo
 * guarda, y lo que el agente NO declara son los destinos que puede tener
 * apagados en ese momento. Eso NO es un rechazo: es un aviso, y el aviso
 * sale de `PrintAgentInventory`, que es su unico sitio.
 */
final readonly class PrintAgentDirectory
{
    public function __construct(
        private Connection $connection,
        private PrintAgentInventory $inventory,
    ) {}

    /**
     * Los AGENTES de una tienda, con lo que declaran y con lo que NO
     * cubren.
     *
     * @return list<array<string, mixed>>
     */
    public function agentes(int $storeId): array
    {
        $filas = $this->connection->table('print_agents')
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get();

        $agentes = [];

        foreach ($filas as $fila) {
            $inventario = $this->decodifica($fila->printers ?? null);
            $capacidades = $this->decodifica($fila->capabilities ?? null);

            $agentes[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'instance_id' => $fila->instance_id === null ? null : (string) $fila->instance_id,
                // EL INTERRUPTOR: se puede apagar y volver a encender.
                'is_active' => (bool) $fila->is_active,
                // LA SENTENCE: la revocacion es definitiva, y solo un
                // operador la levanta.
                'revoked' => $fila->revoked_at !== null,
                'revoked_at' => $fila->revoked_at === null
                    ? null
                    : Carbon::parse((string) $fila->revoked_at)->toIso8601String(),
                'last_heartbeat_at' => $fila->last_heartbeat_at === null
                    ? null
                    : Carbon::parse((string) $fila->last_heartbeat_at)->toIso8601String(),
                'printers' => $inventario,
                'capabilities' => $capacidades,
                // LOS DESTINOS QUE EL INVENTARIO NO CUBRE: un aviso, no un
                // rechazo. Una impresora puede estar apagada en este momento.
                'uncovered_targets' => $this->inventory->destinosNoCubiertos($storeId, $inventario),
            ];
        }

        return $agentes;
    }

    /**
     * Un JSON de la fila, o una lista vacia si no hay o no se puede leer.
     *
     * @return list<mixed>
     */
    private function decodifica(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return is_array($valor) ? array_values($valor) : [];
    }
}
