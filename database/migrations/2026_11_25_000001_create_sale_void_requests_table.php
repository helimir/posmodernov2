<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M50 — las SOLICITUDES de anulacion, UNA FILA por solicitud.
 *
 * `sale_void_requests` es el HISTORIAL de las peticiones de anulacion de una
 * venta. Antes de esta tabla la peticion eran TRES COLUMNAS de la cabecera de
 * `sales` (`void_requested_at`, `void_request_reason`, `void_requested_by`),
 * y eso solo servia mientras una venta tuviera UNA peticion.
 *
 * POR QUE UNA TABLA Y NO MAS COLUMNAS: *una venta puede tener VARIAS
 * solicitudes*. Una peticion rechazada y una nueva son dos hechos, con dos
 * motivos y dos autores, y con tres columnas solo se guarda el ultimo: el
 * rechazo se pierde, y una peticion que se pierde es una peticion que nadie
 * puede defender despues. Con una fila por solicitud, el HISTORIAL es el
 * modelo y la venta solo sabe si tiene una peticion VIVA.
 *
 * Y LA VIVE NO ES UN ESTADO DE LA CABECERA: es la fila `pending` de esta
 * tabla. Por eso las tres columnas se van: dejarlas seria una SEGUNDA fuente
 * de verdad sobre la misma vida de la venta, y dos fuentes de verdad se
 * desincronizan.
 *
 * Y LOS ESTADOS SON TRES, NO CUATRO: `pending`, `rejected` y `cancelled`.
 * El cuarto desenlace —la peticion ATENDIDA, con la venta anulada— NO es un
 * estado de la solicitud: es `sales.status`, que es donde vive el hecho de
 * que la venta quedo anulada. Una solicitud `pending` de una venta anulada
 * esta ATENDIDA, y el CHECK del esquema lo permite sin contradecirse.
 *
 * Y EL ESQUEMA PROHIBE LO IMPOSIBLE: una solicitud rechazada tiene su
 * motivo y su fecha de rechazo, una cancelada tiene su motivo y su fecha de
 * cancelacion, y una pendiente no tiene ninguna de las dos. Y una venta no
 * puede tener DOS solicitudes pendientes: lo dice el indice unico parcial.
 *
 * LA MIGRACION DE LOS DATOS copia las peticiones que vivian en la cabecera.
 * Es ADITIVA (crea lo que no existia) y REVERSIBLE: el `down()` devuelve las
 * tres columnas con la peticion PENDIENTE de cada venta, que es lo que
 * significaban antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_void_requests', function (Blueprint $table): void {
            $table->id();

            // La VENTA de la que es la peticion. Con cascada: una peticion sin
            // venta no es nada, y la venta no se borra nunca.
            $table->foreignId('sale_id')->index()->constrained('sales')->cascadeOnDelete();

            // La TIENDA donde se pidio: la cola de las solicitudes es por
            // tienda, y la peticion es de ESA tienda.
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();

            // El ESTADO: el conjunto cerrado de los tres desenlaces.
            $table->enum('status', ['pending', 'rejected', 'cancelled']);

            // POR QUE se pidio: el motivo lo escribe quien pide, y es el
            // motivo que lleva la anulacion.
            $table->text('reason');

            // QUIEN la pidio, y CUANDO.
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');

            // QUIEN la rechazo, CUANDO y POR QUE: el rechazo es un hecho con
            // su explicacion, y sin ella el cajero no sabe que paso.
            $table->foreignId('rejected_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // QUIEN la cancelo, CUANDO y POR QUE: la cancelacion la hace
            // quien la pidio, y tambien es un hecho con su explicacion.
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // El CAMINO DE ACCESO de la cola: es una consulta por tienda y
            // por estado, y el indice lo pone el ESQUEMA.
            $table->index(
                ['store_id', 'status', 'requested_at'],
                'sale_void_requests_store_status_requested_at_index'
            );
        });

        // EL CHECK DEL ESTADO: una solicitud no puede estar a la vez
        // pendiente y rechazada, ni rechazada y cancelada, ni pendiente con
        // un motivo de rechazo. Lo imposible no entra.
        DB::statement(<<<'SQL'
            ALTER TABLE sale_void_requests ADD CONSTRAINT sale_void_requests_one_decision CHECK (
                (
                    status = 'pending'
                    AND rejected_at IS NULL AND rejection_reason IS NULL
                    AND cancelled_at IS NULL AND cancellation_reason IS NULL
                ) OR (
                    status = 'rejected'
                    AND rejected_at IS NOT NULL AND rejection_reason IS NOT NULL
                    AND cancelled_at IS NULL AND cancellation_reason IS NULL
                ) OR (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
                    AND rejected_at IS NULL AND rejection_reason IS NULL
                )
            )
            SQL);

        // EL CHECK DEL MOTIVO: una solicitud sin motivo es una solicitud que
        // nadie sabe por que se hizo.
        DB::statement(<<<'SQL'
            ALTER TABLE sale_void_requests ADD CONSTRAINT sale_void_requests_reason_present CHECK (
                btrim(reason) <> ''
            )
            SQL);

        // UNA SOLICITUD PENDIENTE POR VENTA: es lo que hace que una segunda
        // peticion no pise la primera. El indice es PARCIAL: solo mira las
        // pendientes, porque las rechazadas y las canceladas pueden repetirse
        // (el historial las guarda).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sale_void_requests_one_pending_per_sale
            ON sale_void_requests (sale_id)
            WHERE status = 'pending'
            SQL);

        // LA MIGRACION DE LOS DATOS: las peticiones que vivian en la
        // cabecera de la venta pasan a ser filas.
        //
        // Y EL MOTIVO NO SE INVENTA: si la cabecera no traia ninguno, la
        // fila dice que no estaba registrado, que es la verdad.
        DB::statement(<<<'SQL'
            INSERT INTO sale_void_requests (
                sale_id, store_id, status, reason, requested_by, requested_at, created_at, updated_at
            )
            SELECT
                sales.id,
                sales.store_id,
                'pending',
                COALESCE(NULLIF(btrim(sales.void_request_reason), ''), 'Sin motivo registrado'),
                sales.void_requested_by,
                sales.void_requested_at,
                sales.void_requested_at,
                sales.void_requested_at
            FROM sales
            WHERE sales.void_requested_at IS NOT NULL
            SQL);

        // Y LAS TRES COLUMNAS SE VAN: la peticion es una fila, no un dato de
        // la cabecera. Dejarlas seria una segunda fuente de verdad.
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_store_id_void_requested_at_index');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['void_requested_by']);
            $table->dropColumn(['void_requested_at', 'void_request_reason', 'void_requested_by']);
        });
    }

    public function down(): void
    {
        // Las tres columnas vuelven, con su clave ajena y su indice.
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestampTz('void_requested_at')->nullable();
            $table->text('void_request_reason')->nullable();
            $table->foreignId('void_requested_by')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete();
            $table->index(
                ['store_id', 'void_requested_at'],
                'sales_store_id_void_requested_at_index'
            );
        });

        // Y la cabecera vuelve a llevar la peticion PENDIENTE de cada venta:
        // era exactamente lo que significaban esas tres columnas.
        DB::statement(<<<'SQL'
            UPDATE sales SET
                void_requested_at = pendientes.requested_at,
                void_request_reason = pendientes.reason,
                void_requested_by = pendientes.requested_by
            FROM (
                SELECT DISTINCT ON (sale_id)
                    sale_id, reason, requested_by, requested_at
                FROM sale_void_requests
                WHERE status = 'pending'
                ORDER BY sale_id, id DESC
            ) AS pendientes
            WHERE sales.id = pendientes.sale_id
            SQL);

        Schema::dropIfExists('sale_void_requests');
    }
};
