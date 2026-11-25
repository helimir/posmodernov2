<?php

namespace App\Domain\Reports;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * El INVENTARIO VALORADO: lo que hay en el almacen y cuanto vale.
 *
 * REGLA que fija esta clase: *el inventario valorado se deriva de los LOTES*,
 * nunca del cache. La verdad del stock son los lotes (`inventory_lots`) y su
 * `available`; el cache (`product_stores.quantity_in_stock`) es una COPIA, y
 * una copia se puede desfasar. Un reporte sobre el cache es un reporte que
 * puede no avisar de lo que se acaba.
 *
 * Y LA VALORACION es la de cada LOTE: lo que queda en un lote vale lo que
 * costo ese lote. Con el consumo FIFO (el mas antiguo primero), lo que queda
 * de un lote es su cola, y cada cola vale a su propio coste: esa es la
 * valoracion que sigue el consumo, sin inventar un coste medio.
 *
 * Y LAS CAPAS se enseñan en el ORDEN del FIFO (`received_at ASC, id ASC`): el
 * mismo orden que consume el stock, para que el lector vea las capas de la
 * misma manera que las ve el sistema.
 *
 * Y ES UNA FOTO: el inventario valorado es el de AHORA. No hay un historial
 * de valoraciones, y un reporte que fingiera uno inventaria datos.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class ValuedInventoryReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El INVENTARIO del ambito, valorado.
     *
     * @return array{
     *     rows: list<array{product_id: int, code: string, name: string, store_id: int, store_name: string, quantity: string, value: string, layers: list<array{lot_id: int, received_at: string, available: string, unit_cost: string, value: string}>}>,
     *     totals: array{quantity: string, value: string}
     * }
     */
    public function build(ReportScope $scope): array
    {
        $consulta = $this->connection->table('inventory_lots')
            ->join('products', 'products.id', '=', 'inventory_lots.product_id')
            ->join('stores', 'stores.id', '=', 'inventory_lots.store_id')
            ->where('inventory_lots.available', '>', 0);

        $scope->restrict($consulta, 'inventory_lots.store_id');

        $filas = $consulta
            ->orderBy('inventory_lots.product_id')
            ->orderBy('inventory_lots.store_id')
            ->orderBy('inventory_lots.received_at')
            ->orderBy('inventory_lots.id')
            ->selectRaw(
                'inventory_lots.id as lot_id, '
                .'inventory_lots.product_id as product_id, '
                .'products.code as code, '
                .'products.name as name, '
                .'inventory_lots.store_id as store_id, '
                .'stores.name as store_name, '
                .'inventory_lots.received_at as received_at, '
                .'inventory_lots.available as available, '
                .'inventory_lots.unit_cost as unit_cost'
            )
            ->get();

        $grupos = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->product_id.'|'.(int) $fila->store_id;

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'product_id' => (int) $fila->product_id,
                    'code' => (string) $fila->code,
                    'name' => (string) $fila->name,
                    'store_id' => (int) $fila->store_id,
                    'store_name' => (string) $fila->store_name,
                    'layers' => [],
                ];
            }

            $disponible = PosAmount::text($fila->available);
            $coste = PosAmount::text($fila->unit_cost);

            $grupos[$clave]['layers'][] = [
                'lot_id' => (int) $fila->lot_id,
                'received_at' => Carbon::parse((string) $fila->received_at)->format('Y-m-d H:i:sP'),
                'available' => $disponible,
                'unit_cost' => $coste,
                'value' => $this->producto($disponible, $coste),
            ];
        }

        $rows = [];

        $totales = ['quantity' => '0.0000', 'value' => '0.0000'];

        foreach ($grupos as $grupo) {
            $cantidad = '0.0000';
            $valor = '0.0000';

            foreach ($grupo['layers'] as $capa) {
                $cantidad = $this->suma($cantidad, $capa['available']);
                $valor = $this->suma($valor, $capa['value']);
            }

            $totales['quantity'] = $this->suma($totales['quantity'], $cantidad);
            $totales['value'] = $this->suma($totales['value'], $valor);

            $rows[] = [
                'product_id' => $grupo['product_id'],
                'code' => $grupo['code'],
                'name' => $grupo['name'],
                'store_id' => $grupo['store_id'],
                'store_name' => $grupo['store_name'],
                'quantity' => $cantidad,
                'value' => $valor,
                'layers' => $grupo['layers'],
            ];
        }

        // El ORDEN es por valor: lo que mas vale, lo primero.
        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return ['rows' => $rows, 'totals' => $totales];
    }

    /**
     * El VALOR de una capa: lo que queda, a su propio coste.
     */
    private function producto(string $cantidad, string $coste): string
    {
        return (string) BigDecimal::of($cantidad)
            ->multipliedBy(BigDecimal::of($coste))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * La SUMA de dos importes, a la escala de la columna.
     */
    private function suma(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)
            ->plus(BigDecimal::of($b))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }
}
