<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * El comando que BLOQUEA el arranque operativo.
 *
 * REGLA que fija: sin saldos de apertura firmados no hay cifras defendibles.
 * Los saldos de apertura (stock por tienda y producto, saldo y deuda por
 * cliente y proveedor, saldo de caja por cuenta y tienda) tienen que estar
 * CONGELADOS y CERTIFICADOS —con fecha (`certified_at`) y responsable nominal
 * (`certified_by`)— ANTES de la primera operacion.
 *
 * Este comando NO certifica: la certificacion es un ACTO HUMANO, de la
 * direccion financiera, porque un saldo contable no lo aprueba un script. El
 * comando es el mecanismo que hace OPERATIVA esa dependencia, no su
 * sustituto.
 *
 * Es de SOLO LECTURA: no escribe NADA.
 *
 * El stock se comprueba con `product_stores.quantity_in_stock`, el CACHE que
 * la caja ve y con el que la direccion financiera reconcilia, y no con la
 * suma de los lotes: el comando mira la MISMA cifra que la operacion.
 */
class CheckOpeningBalancesCommand extends Command
{
    protected $signature = 'ops:check-opening-balances';

    protected $description = 'Bloquea el arranque operativo mientras falten saldos de apertura certificados.';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    /**
     * Comprueba los saldos de apertura de cada tienda activa.
     *
     * REGLAS que fija este metodo:
     *
     *  1. Cada par (tienda, producto) con stock exige un saldo de apertura de
     *     tipo `stock` CERTIFICADO; cada par (tienda, cliente) con saldo o
     *     deuda, uno de tipo `customer_balance`; cada par (tienda,
     *     proveedor) con saldo o deuda, uno de tipo `supplier_balance`; y cada
     *     par (tienda, cuenta bancaria), uno de tipo `cash`.
     *  2. CERTIFICADO es `certified_at` Y `certified_by`: una fila sin los dos
     *     no vale.
     *  3. Si falta una certificacion, el comando DICE cual falta (el tipo de
     *     saldo, la tienda y la entidad) y sale con codigo distinto de cero.
     *     Si no falta ninguna, sale con codigo cero.
     *  4. Sin tiendas activas, o sin nada que comprobar, no hay nada que
     *     bloquear: el comando sale con codigo cero.
     */
    public function handle(): int
    {
        $tiendas = $this->connection->table('stores')
            ->where('status', true)
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tiendas->isEmpty()) {
            $this->info('No hay tiendas activas: no hay nada que bloquear.');

            return self::SUCCESS;
        }

        $faltantes = [];
        $comprobados = 0;

        foreach ($tiendas as $tienda) {
            $storeId = (int) $tienda->id;
            $nombreTienda = (string) $tienda->name;

            $certificados = $this->certificadosPorTipo($storeId);

            foreach ($this->requisitos($storeId) as $requisito) {
                $comprobados++;

                if (in_array($requisito['id'], $certificados[$requisito['kind']], true)) {
                    continue;
                }

                $faltantes[] = "[{$requisito['etiqueta']}] Tienda: {$nombreTienda} | {$requisito['entidad']}: {$requisito['nombre']}";
            }
        }

        if ($faltantes !== []) {
            $this->error('El arranque operativo queda BLOQUEADO: faltan saldos de apertura certificados.');

            foreach ($faltantes as $faltante) {
                $this->line($faltante);
            }

            $this->line('Saldos de apertura comprobados: '.$comprobados.'. Certificaciones que faltan: '.count($faltantes).'.');

            return self::FAILURE;
        }

        if ($comprobados === 0) {
            $this->info('No hay saldos de apertura que comprobar: no hay nada que bloquear.');

            return self::SUCCESS;
        }

        $this->info('Saldos de apertura comprobados: '.$comprobados.'. Todos estan certificados.');

        return self::SUCCESS;
    }

    /**
     * Los saldos que esta tienda tiene que tener CERTIFICADOS.
     *
     * El stock se mira en `product_stores.quantity_in_stock` (el cache que ve
     * la caja), el saldo y la deuda del cliente en `customer_stores`, los del
     * proveedor en `supplier_stores`, y la caja en las filas de
     * `bank_account_stores`: una cuenta bancaria en una tienda es un saldo de
     * caja por cuenta y tienda, aunque valga cero.
     *
     * @return array<int, array{kind: string, columna: string, id: int, etiqueta: string, entidad: string, nombre: string}>
     */
    private function requisitos(int $storeId): array
    {
        return array_merge(
            $this->requisitosDe(
                'stock',
                'product_id',
                'saldo de stock',
                'Producto',
                $this->connection->table('product_stores')
                    ->join('products', 'products.id', '=', 'product_stores.product_id')
                    ->where('product_stores.store_id', $storeId)
                    ->where('product_stores.quantity_in_stock', '<>', 0)
                    ->orderBy('product_stores.product_id')
                    ->select(['product_stores.product_id as entity_id', 'products.name as entity_name'])
            ),
            $this->requisitosDe(
                'customer_balance',
                'customer_id',
                'saldo de cliente',
                'Cliente',
                $this->connection->table('customer_stores')
                    ->join('customers', 'customers.id', '=', 'customer_stores.customer_id')
                    ->where('customer_stores.store_id', $storeId)
                    ->where(function (Builder $consulta): void {
                        $consulta->where('customer_stores.balance', '<>', 0)
                            ->orWhere('customer_stores.due', '<>', 0);
                    })
                    ->orderBy('customer_stores.customer_id')
                    ->select(['customer_stores.customer_id as entity_id', 'customers.name as entity_name'])
            ),
            $this->requisitosDe(
                'supplier_balance',
                'supplier_id',
                'saldo de proveedor',
                'Proveedor',
                $this->connection->table('supplier_stores')
                    ->join('suppliers', 'suppliers.id', '=', 'supplier_stores.supplier_id')
                    ->where('supplier_stores.store_id', $storeId)
                    ->where(function (Builder $consulta): void {
                        $consulta->where('supplier_stores.balance', '<>', 0)
                            ->orWhere('supplier_stores.due', '<>', 0);
                    })
                    ->orderBy('supplier_stores.supplier_id')
                    ->select(['supplier_stores.supplier_id as entity_id', 'suppliers.name as entity_name'])
            ),
            $this->requisitosDe(
                'cash',
                'bank_account_id',
                'saldo de caja',
                'Cuenta bancaria',
                $this->connection->table('bank_account_stores')
                    ->join('bank_accounts', 'bank_accounts.id', '=', 'bank_account_stores.bank_account_id')
                    ->where('bank_account_stores.store_id', $storeId)
                    ->orderBy('bank_account_stores.bank_account_id')
                    ->select(['bank_account_stores.bank_account_id as entity_id', 'bank_accounts.name as entity_name'])
            ),
        );
    }

    /**
     * Los requisitos de un tipo de saldo, con la entidad que los nombra.
     *
     * @param  string  $kind  Tipo de saldo en `opening_balances`.
     * @param  string  $columna  Columna que identifica la entidad.
     * @param  string  $etiqueta  Como se le dice al saldo en la salida.
     * @param  string  $entidad  Como se le dice a la entidad en la salida.
     * @param  Builder  $consulta  Los pares que exigen certificacion.
     * @return array<int, array{kind: string, columna: string, id: int, etiqueta: string, entidad: string, nombre: string}>
     */
    private function requisitosDe(string $kind, string $columna, string $etiqueta, string $entidad, Builder $consulta): array
    {
        $requisitos = [];

        foreach ($consulta->get() as $fila) {
            $requisitos[] = [
                'kind' => $kind,
                'columna' => $columna,
                'id' => (int) $fila->entity_id,
                'etiqueta' => $etiqueta,
                'entidad' => $entidad,
                'nombre' => (string) $fila->entity_name,
            ];
        }

        return $requisitos;
    }

    /**
     * Los ids de las entidades con saldo CERTIFICADO de cada tipo, en una
     * tienda.
     *
     * CERTIFICADO es `certified_at` Y `certified_by`: una fila con uno de los
     * dos a NULL no vale, y el saldo sigue sin certificar.
     *
     * @return array<string, array<int, int>>
     */
    private function certificadosPorTipo(int $storeId): array
    {
        return [
            'stock' => $this->certificados('stock', $storeId, 'product_id'),
            'customer_balance' => $this->certificados('customer_balance', $storeId, 'customer_id'),
            'supplier_balance' => $this->certificados('supplier_balance', $storeId, 'supplier_id'),
            'cash' => $this->certificados('cash', $storeId, 'bank_account_id'),
        ];
    }

    /**
     * Los ids CERTIFICADOS de un tipo de saldo en una tienda.
     *
     * @return array<int, int>
     */
    private function certificados(string $kind, int $storeId, string $columna): array
    {
        return $this->connection->table('opening_balances')
            ->where('store_id', $storeId)
            ->where('kind', $kind)
            ->whereNotNull('certified_at')
            ->whereNotNull('certified_by')
            ->pluck($columna)
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
