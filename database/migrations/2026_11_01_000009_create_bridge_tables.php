<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M09 — los bridges multi-tienda.
 *
 * El patron maestro/bridge: la tabla maestra guarda la IDENTIDAD y el bridge
 * guarda el ESTADO POR TIENDA (precio, stock, saldo, deuda). Todos los
 * bridges tienen FK real a `stores`, `status` y `sort_order`.
 *
 * `product_stores.purchase_price` / `sell_price` son `numeric(25,4)`: en el
 * legacy eran `float`, y un `float` no es un dinero.
 *
 * `bank_account_stores` NO lleva `deposit` / `withdraw` / `transfer_*`: los
 * contadores globales por cuenta duplicaban el saldo real y lo cambiaban al
 * borrar una operacion.
 *
 * `user_stores` NO lleva el `JOIN status=1` del legacy, que hacia que
 * desactivar a un usuario en una tienda lo bloqueara en todas.
 *
 * `customer_stores` y `supplier_stores` apuntan a los maestros que crea M10:
 * su FK se declara alli, cuando el maestro ya existe. Lo mismo con
 * `bank_account_stores`: el plan NO asigna migracion al maestro
 * `bank_accounts`, de modo que su FK queda diferida hasta que exista.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: bool}> tabla => [columna, maestro, con_fk]
     */
    private const BRIDGES = [
        'customer_stores' => ['customer_id', 'customers', false],
        'supplier_stores' => ['supplier_id', 'suppliers', false],
        'brand_stores' => ['brand_id', 'brands', true],
        'category_stores' => ['category_id', 'categories', true],
        'box_stores' => ['box_id', 'boxes', true],
        'unit_stores' => ['unit_id', 'units', true],
        'currency_stores' => ['currency_id', 'currencies', true],
        'payment_method_stores' => ['payment_method_id', 'payment_methods', true],
        'pos_template_stores' => ['pos_template_id', 'pos_templates', true],
        'bank_account_stores' => ['bank_account_id', 'bank_accounts', false],
        'user_stores' => ['user_id', 'users', true],
    ];

    public function up(): void
    {
        // El stock y los precios viven en el bridge, no en el maestro.
        Schema::create('product_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->index()->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('tax_category_id')->nullable()->index()->constrained('tax_categories')->nullOnDelete();
            $table->decimal('purchase_price', 25, 4)->default(0);
            $table->decimal('sell_price', 25, 4)->default(0);
            // Cacheado, con invariante: la suma de los lotes disponibles.
            $table->decimal('quantity_in_stock', 25, 4)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'store_id']);
        });

        foreach (self::BRIDGES as $name => [$column, $master, $withForeignKey]) {
            Schema::create($name, function (Blueprint $table) use ($column, $master, $withForeignKey): void {
                $table->id();
                $table->foreignId($column)->index();
                $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                if ($withForeignKey) {
                    $table->foreign($column)->references('id')->on($master)->cascadeOnDelete();
                }
            });
        }

        // El saldo del cliente y la deuda con el proveedor viven en su bridge.
        Schema::table('customer_stores', function (Blueprint $table): void {
            $table->decimal('balance', 25, 4)->default(0);
            $table->decimal('due', 25, 4)->default(0);
        });

        Schema::table('supplier_stores', function (Blueprint $table): void {
            $table->decimal('balance', 25, 4)->default(0);
            $table->decimal('due', 25, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stores');

        foreach (array_keys(self::BRIDGES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
