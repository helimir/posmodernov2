<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M28 — los INDICES y las RESTRICCIONES del dominio.
 *
 * El esquema entrega las tablas y sus FK; esta migracion entrega el
 * RENDIMIENTO y las REGLAS que el esquema puede imponer:
 *
 *  - Una FK SIN indice no es un problema de estilo: es un RECORRIDO
 *    SECUENCIAL en cada escritura que la toca. En las tablas calientes (los
 *    lotes, las lineas de venta, los asientos) ese recorrido se paga en cada
 *    cobro de la caja. El indice lo pone el ESQUEMA, no la disciplina de
 *    quien escribe.
 *
 *  - Toda columna usada en FILTRO y todo `store_id` indexados (design A.2).
 *    El `store_id` ya lo esta en todas las tablas: el alcance por tienda es
 *    la regla del dominio y se filtra en todas partes. Los indices que faltan
 *    son los de los caminos de acceso que el dominio consulta de verdad.
 *
 *  - El indice PARCIAL de los lotes DISPONIBLES: `available` es una columna
 *    GENERATED (M14), asi que no se indexa por si sola, y un lote ya
 *    consumido es una fila que el FIFO nunca consulta.
 *
 *  - Los CHECK del lote: `quantity_consumed` no puede ser negativo y
 *    `available` no puede ser negativo. El esquema lo garantiza, no la
 *    confianza en el codigo.
 *
 * EL NUMERO: el plan llama a esta migracion "M28", pero los numeros 000027 y
 * 000028 ya estan usados por otras unidades (los saldos de apertura y la FK
 * de pais de la tienda). Ademas, esta migracion tiene que correr DESPUES de
 * TODAS las demas: las FK de las tablas que crean las ultimas migraciones
 * (M29-M36) todavia no existen cuando una migracion 000028 correria. Por eso
 * va con el 000037, que es el siguiente numero libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. La FK que se quedaba sin indice: `print_jobs.created_by` es la
        //    unica del esquema que no lo tenia.
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->index('created_by', 'print_jobs_created_by_index');
        });

        // 2. Los CAMINOS DE ACCESO que el dominio consulta y que ningun
        //    indice cubria.
        //
        //    - La cola de impresion: el agente busca su trabajo y el barrido
        //      libera los leasing caducados.
        //    - La caja: el registro ABIERTO de la tienda y su fecha.
        //    - La venta: el corte por dia de negocio de una tienda.
        //    - El libro: el asiento que origino un hecho (el enlace es el
        //      mismo que el de la reserva fiscal, que M31 ya indexa).
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->index(
                ['store_id', 'status', 'lease_expires_at'],
                'print_jobs_store_status_lease_expires_at_index'
            );
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->index(
                ['store_id', 'status', 'business_date'],
                'cash_registers_store_status_business_date_index'
            );
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->index(['store_id', 'sale_date'], 'sales_store_id_sale_date_index');
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index(
                ['reference_type', 'reference_id'],
                'ledger_entries_reference_type_reference_id_index'
            );
        });

        // 3. El indice PARCIAL de los lotes DISPONIBLES. `available` es
        //    GENERATED: se filtra por el valor, y el indice solo lleva las
        //    filas que todavia tienen stock.
        DB::statement(
            'CREATE INDEX inventory_lots_available_partial_index'
            .' ON inventory_lots (product_id, store_id, received_at, id)'
            .' WHERE available > 0'
        );

        // 4. Los CHECK del lote. El consumo no puede ser negativo, y la
        //    disponibilidad no puede ser negativa: un lote que debe stock es
        //    un lote que el FIFO no puede dar.
        DB::statement(
            'ALTER TABLE inventory_lots'
            .' ADD CONSTRAINT inventory_lots_quantity_consumed_non_negative'
            .' CHECK (quantity_consumed >= 0)'
        );

        DB::statement(
            'ALTER TABLE inventory_lots'
            .' ADD CONSTRAINT inventory_lots_available_non_negative'
            .' CHECK (available >= 0)'
        );
    }

    public function down(): void
    {
        // Los CHECK y el indice parcial se van primero: los indices de
        // columnas no dependen de ellos.
        DB::statement('ALTER TABLE inventory_lots DROP CONSTRAINT IF EXISTS inventory_lots_available_non_negative');
        DB::statement('ALTER TABLE inventory_lots DROP CONSTRAINT IF EXISTS inventory_lots_quantity_consumed_non_negative');

        DB::statement('DROP INDEX IF EXISTS inventory_lots_available_partial_index');

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_entries_reference_type_reference_id_index');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_store_id_sale_date_index');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropIndex('cash_registers_store_status_business_date_index');
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropIndex('print_jobs_store_status_lease_expires_at_index');
            $table->dropIndex('print_jobs_created_by_index');
        });
    }
};
