<?php

namespace App\Print;

use Illuminate\Database\Connection;

/**
 * El INVENTARIO de impresoras que declara el agente LAN.
 *
 * REGLA que fija esta clase: *el inventario se NORMALIZA en un solo sitio, y
 * un destino esta CUBIERTO cuando el inventario declara el codigo de su
 * impresora*.
 *
 * La regla vive aqui y en ningun otro sitio, para que el heartbeat y la
 * pantalla de destinos no puedan separarse: los dos tienen que avisar de los
 * MISMOS destinos.
 *
 * Y LA COBERTURA NO ES UN RECHAZO: una impresora puede estar apagada en ese
 * momento, y rechazar el trabajo seria perder un ticket que si se puede
 * imprimir. Aqui solo se AVISA.
 */
final class PrintAgentInventory
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * El INVENTARIO declarado, normalizado.
     *
     * El contrato del agente acepta `key` (el nombre del research) o `code`:
     * los dos se guardan en `code`, que es como lo llama el trabajo
     * (`printer_key`).
     *
     * @return list<array<string, mixed>>
     */
    public function normalizar(mixed $declarado): array
    {
        if (! is_array($declarado)) {
            return [];
        }

        $inventario = [];

        foreach ($declarado as $item) {
            if (! is_array($item)) {
                continue;
            }

            $codigo = trim((string) ($item['code'] ?? $item['key'] ?? ''));
            $nombre = trim((string) ($item['name'] ?? ''));

            if ($codigo === '' && $nombre === '') {
                continue;
            }

            $impresora = [
                'code' => $codigo,
                'name' => $nombre,
            ];

            if (isset($item['profile']) && is_string($item['profile'])) {
                $impresora['profile'] = trim($item['profile']);
            }

            if (isset($item['char_per_line']) && is_numeric($item['char_per_line'])) {
                $impresora['char_per_line'] = (int) $item['char_per_line'];
            }

            if (isset($item['status']) && is_string($item['status'])) {
                $impresora['status'] = trim($item['status']);
            }

            $inventario[] = $impresora;
        }

        return $inventario;
    }

    /**
     * Los DESTINOS de la tienda que el inventario NO cubre.
     *
     * El codigo de una impresora es su clave natural: si el inventario lo
     * declara, el codigo es lo que decide. El NOMBRE solo hace falta cuando
     * el agente no declara codigo, porque dos impresoras pueden llamarse
     * igual.
     *
     * @param  list<array<string, mixed>>  $inventario
     * @return list<array<string, mixed>>
     */
    public function destinosNoCubiertos(int $storeId, array $inventario): array
    {
        // Sin INVENTARIO declarado no se avisa de nada: un agente que todavia
        // no ha declarado sus impresoras no es un desajuste, y avisar de todos
        // los destinos enterraria la senal de verdad.
        if ($inventario === []) {
            return [];
        }

        $porCodigo = [];
        $porNombre = [];

        foreach ($inventario as $impresora) {
            $codigo = trim((string) ($impresora['code'] ?? ''));
            $nombre = trim((string) ($impresora['name'] ?? ''));

            if ($codigo !== '') {
                $porCodigo[mb_strtolower($codigo)] = true;

                continue;
            }

            if ($nombre !== '') {
                $porNombre[mb_strtolower($nombre)] = true;
            }
        }

        $destinos = $this->connection->table('print_targets')
            ->join('printers', 'printers.id', '=', 'print_targets.printer_id')
            ->where('print_targets.store_id', $storeId)
            ->where('print_targets.transport', 'lan_agent')
            ->where('print_targets.is_active', true)
            ->orderBy('print_targets.name')
            ->get([
                'print_targets.id as id',
                'print_targets.name as name',
                'printers.code as printer_code',
                'printers.name as printer_name',
            ]);

        $avisos = [];

        foreach ($destinos as $destino) {
            $codigo = mb_strtolower((string) $destino->printer_code);
            $nombre = mb_strtolower((string) $destino->printer_name);

            if (isset($porCodigo[$codigo]) || isset($porNombre[$nombre])) {
                continue;
            }

            $avisos[] = [
                'target_id' => (int) $destino->id,
                'name' => (string) $destino->name,
                'printer_code' => (string) $destino->printer_code,
                'printer_name' => (string) $destino->printer_name,
                'reason' => 'El inventario del agente no declara esta impresora.',
            ];
        }

        return $avisos;
    }
}
