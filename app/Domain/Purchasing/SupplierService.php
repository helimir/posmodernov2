<?php

namespace App\Domain\Purchasing;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El PROVEEDOR: su ficha, su ficha de tienda y su DEUDA.
 *
 * REGLA que fija esta clase: *el maestro del proveedor guarda la IDENTIDAD y
 * su ficha de tienda guarda el ESTADO POR TIENDA* (el patron maestro/bridge
 * del repositorio). Un proveedor existe en el catalogo; el que compra a la
 * tienda es su ficha en esa tienda.
 *
 * Y LA DEUDA AL PROVEEDOR es UN SALDO DERIVADO, no un contador: sale de los
 * HECHOS que la crean y la borran, que son las compras y las devoluciones.
 * El cache (`supplier_stores.balance` y `supplier_stores.due`) se refresca
 * DESPUES de cada hecho, en la MISMA transaccion: el cache se puede desfasar,
 * la verdad no.
 *
 * Y DONDE VIVE LA DEUDA: en el BRIDGE (`supplier_stores.balance`), que es lo
 * que el esquema tiene. El repositorio NO tiene un libro de proveedores
 * (el mini-libro que hay es el de los CLIENTES: `customer_ledger_entries`), y
 * aqui no se inventa uno: la verdad del saldo del proveedor son sus compras y
 * sus devoluciones, que son las filas que ya estan en el esquema.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class SupplierService
{
    /** La escala de las columnas: `numeric(25,4)`. */
    private const SCALE = 4;

    public function __construct(private Connection $connection) {}

    /**
     * DA DE ALTA un proveedor y le abre ficha en la tienda.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El NOMBRE es OBLIGATORIO: un proveedor sin nombre no se puede
     *     distinguir de otro.
     *  2. El proveedor nace con FICHA en la tienda: la compra es una relacion
     *     entre una tienda y un proveedor, y sin ficha no hay donde dejar la
     *     deuda.
     *
     * @param  array<string, mixed>  $datos  Los datos del maestro. Los
     *                                       importes no se aceptan aqui: el
     *                                       proveedor no tiene saldo propio.
     * @return int El id del proveedor.
     *
     * @throws RuntimeException Cuando el nombre no esta.
     */
    public function register(int $storeId, array $datos): int
    {
        $nombre = trim((string) ($datos['name'] ?? ''));

        if ($nombre === '') {
            throw new RuntimeException(
                'Un proveedor necesita un NOMBRE: un proveedor sin nombre no se puede distinguir de otro.'
            );
        }

        return $this->connection->transaction(function () use ($storeId, $datos, $nombre): int {
            $supplierId = (int) $this->connection->table('suppliers')->insertGetId([
                'name' => $nombre,
                'email' => $this->texto($datos['email'] ?? null),
                'phone' => $this->texto($datos['phone'] ?? null),
                'mobile' => $this->texto($datos['mobile'] ?? null),
                'address' => $this->texto($datos['address'] ?? null),
                'city' => $this->texto($datos['city'] ?? null),
                'zip_code' => $this->texto($datos['zip_code'] ?? null),
                'country_id' => $this->entero($datos['country_id'] ?? null),
                'state' => $this->texto($datos['state'] ?? null),
                'tax_id' => $this->texto($datos['tax_id'] ?? null),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->abreFicha($supplierId, $storeId);

            return $supplierId;
        });
    }

    /**
     * El SALDO del proveedor en una tienda: lo que la tienda le DEBE.
     *
     * REGLA que fija este metodo: *el saldo del proveedor se DERIVA de sus
     * hechos*: las compras que no estan anuladas (suman), las devoluciones
     * (restan) y los pagos (restan). El signo va en el importe: positivo es
     * deuda, y una deuda NUNCA es negativa (un saldo a favor del proveedor es
     * un anticipo, y no es una deuda).
     *
     * @return string El saldo, como texto decimal.
     */
    public function balance(int $supplierId, int $storeId): string
    {
        $compras = $this->connection->table('purchases')
            ->join('purchase_totals', 'purchase_totals.purchase_id', '=', 'purchases.id')
            ->where('purchases.store_id', $storeId)
            ->where('purchases.supplier_id', $supplierId)
            ->where('purchases.status', '!=', 'cancelled')
            ->sum('purchase_totals.grand_total');

        $devoluciones = $this->connection->table('purchase_returns')
            ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->where('purchase_returns.store_id', $storeId)
            ->where('purchases.supplier_id', $supplierId)
            ->sum('purchase_returns.total');

        $pagos = $this->connection->table('purchase_payments')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->where('purchases.store_id', $storeId)
            ->where('purchases.supplier_id', $supplierId)
            ->sum('purchase_payments.amount');

        $saldo = BigDecimal::of($this->decimal($compras ?? '0'))
            ->minus(BigDecimal::of($this->decimal($devoluciones ?? '0')))
            ->minus(BigDecimal::of($this->decimal($pagos ?? '0')))
            ->toScale(self::SCALE, RoundingMode::HALF_UP);

        // La DEUDA es el max(0, saldo): un saldo a favor del proveedor no es
        // una deuda, es un anticipo.
        if ($saldo->isNegative()) {
            return (string) BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP);
        }

        return (string) $saldo;
    }

    /**
     * REFRESCA el cache del saldo del proveedor.
     *
     * Se llama DENTRO de la transaccion del hecho que lo cambia: si el hecho
     * se revierte, el cache tambien.
     */
    public function refreshCache(int $supplierId, int $storeId): void
    {
        $saldo = $this->balance($supplierId, $storeId);

        $this->connection->table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $storeId)
            ->update([
                'balance' => $saldo,
                'due' => $saldo,
                'updated_at' => now(),
            ]);
    }

    /**
     * El proveedor TIENE ficha en la tienda.
     *
     * REGLA que fija este metodo: *la compra necesita la ficha del proveedor
     * en la tienda*: sin ella no hay donde dejar la deuda, ni donde derivar
     * el saldo. Y el fallo lo dice, con su mensaje.
     *
     * @throws RuntimeException Cuando el proveedor no tiene ficha en la
     *                          tienda.
     */
    public function exigeFicha(int $supplierId, int $storeId): void
    {
        $existe = $this->connection->table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $storeId)
            ->exists();

        if ($existe) {
            return;
        }

        throw new RuntimeException(
            "El proveedor {$supplierId} no tiene ficha en la tienda {$storeId}: "
            .'su compra en esa tienda no tiene donde dejar la deuda.'
        );
    }

    /**
     * Abre la ficha del proveedor en la tienda, si no la tiene.
     */
    private function abreFicha(int $supplierId, int $storeId): void
    {
        $existe = $this->connection->table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $storeId)
            ->exists();

        if ($existe) {
            return;
        }

        $this->connection->table('supplier_stores')->insert([
            'supplier_id' => $supplierId,
            'store_id' => $storeId,
            'balance' => '0.0000',
            'due' => '0.0000',
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un TEXTO de la entrada, o `null` si no hay.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Un ENTERO de la entrada, o `null` si no hay.
     */
    private function entero(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Normaliza un importe a la escala de la columna.
     */
    private function decimal(mixed $valor): string
    {
        return PosAmount::text($valor);
    }
}
