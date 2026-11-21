<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M43 — el Z SELLADO: `cash_closes`.
 *
 * El CIERRE de caja es un HECHO, y un hecho se escribe una vez. Antes de esta
 * tabla el descuadre se CALCULABA en vivo (`CashDay`), asi que corregir una
 * venta de ayer cambiaba el "cierre" de ayer: un cierre que se puede reescribir
 * desde el pasado no es un cierre, es un calculo.
 *
 * Aqui el Z es una FILA, y una fila por CIERRE (no una por dia): el historial
 * es el motivo de que la tabla exista. Un dia que se reabre no pierde su Z:
 * la fila queda marcada (`reopened_at`, `reopened_by`, `reopen_reason`) y, al
 * volver a cerrar, se escribe OTRA fila que es la vigente. La vigente es la
 * ULTIMA fila no reabierta de esa tienda y esa fecha.
 *
 * EL CONTENIDO del Z son COLUMNAS, no un json: el Z se consulta, y un Z que
 * hay que deserializar para saber cuanto se conto no es un Z consultable. El
 * desglose por medio de pago si es un json: es una lista variable de filas,
 * y su forma la decide el negocio.
 *
 * EL HASH (`content_hash`) es la FIRMA del contenido: `sha256` de la forma
 * canonica del Z. Se comprueba AL LEER: si el hash no cuadra, alguien ALTERO
 * un Z sellado, y eso se dice.
 *
 * Y LOS IMPORTES viajan como TEXTO decimal (`numeric(25,4)`): un flotante no
 * es un dinero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();

            // La FECHA DE NEGOCIO del dia que se cerro: un dato, no un
            // `DAY()/MONTH()/YEAR()` calculado.
            $table->date('business_date');

            // La caja que se cerro. No es la clave natural (esa es el par
            // tienda + fecha), pero deja el Z pegado a su caja sin ambiguedad.
            $table->foreignId('cash_register_id')->index()->constrained('cash_registers')->restrictOnDelete();

            // EL CONTENIDO DEL Z.
            //
            // La PARTIDA es el dato que se conto; el ESPERADO es lo que decia
            // el libro en ese momento; el CONTADO es lo que se conto otra vez,
            // a mano; y el DESCUADRE es la diferencia, CON SU SIGNO.
            $table->decimal('opening_balance', 25, 4)->default(0);
            $table->decimal('expected_balance', 25, 4)->default(0);
            $table->decimal('counted_balance', 25, 4)->default(0);
            $table->decimal('difference', 25, 4)->default(0);

            // Los TOTALES del dia y el numero de ventas.
            $table->decimal('subtotal', 25, 4)->default(0);
            $table->decimal('tax_total', 25, 4)->default(0);
            $table->decimal('grand_total', 25, 4)->default(0);
            $table->unsignedInteger('sales_count')->default(0);

            // LOS CORRELATIVOS: el primer y el ultimo documento, y el primer
            // y el ultimo control. El rango del dia es lo que dice si falta
            // un comprobante.
            $table->string('first_document_number', 100)->nullable();
            $table->string('last_document_number', 100)->nullable();
            $table->string('first_control_number', 50)->nullable();
            $table->string('last_control_number', 50)->nullable();

            // EL DESGLOSE por medio de pago: lo que entro en la caja y por
            // donde. Es una lista variable, y por eso es un json.
            $table->jsonb('payments')->default('[]');

            // LA FIRMA del contenido: `sha256` de la forma canonica del Z.
            $table->string('content_hash', 64);

            // QUIEN CERRO y CUANDO.
            $table->foreignId('closed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at');

            // Y SI SE REABRIO: con quien y con que motivo. El Z anterior NO
            // se borra: se queda, marcado.
            $table->timestampTz('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 255)->nullable();

            $table->timestamps();

            // El indice del historial: el Z de una tienda y una fecha.
            $table->index(['store_id', 'business_date'], 'cash_closes_store_business_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closes');
    }
};
