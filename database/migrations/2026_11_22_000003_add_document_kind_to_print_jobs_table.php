<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M46 — un TRABAJO de impresion dice QUE documento imprime.
 *
 * `document_kind` es el TIPO de documento que sale por la impresora: un
 * COMPROBANTE fiscal o el Z de un cierre de caja. Antes, un trabajo de impresion
 * no decia que era, y un Z era indistinguible de un comprobante: se podia
 * reimprimir un comprobante (que no consume correlativo) sin que nadie supiera
 * que estaba sacando el documento del cierre de caja otra vez.
 *
 * `cash_close_id` es el VINCULO con el Z que ese trabajo imprime. Es lo que
 * permite decir "este trabajo reimprime el Z 42" sin adivinarlo.
 *
 * Y EL MOTIVO (`reason`) es el POR QUE: un Z reimpreso lo lleva, y el motivo
 * es DATO, no codigo.
 *
 * EL CHECK del lote deja las tres reglas del esquema:
 *
 *  - solo se admiten los dos tipos de documento que el sistema imprime;
 *  - un trabajo de Z SIEMPRE apunta a su Z;
 *  - un trabajo de comprobante NUNCA apunta a un Z.
 *
 * El esquema lo garantiza, no la confianza en el codigo: un trabajo que no
 * cumple la regla no entra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            // QUE documento imprime el trabajo.
            $table->string('document_kind', 32)->default('receipt')->after('status');

            // El Z que imprime, cuando imprime un Z.
            $table->foreignId('cash_close_id')
                ->nullable()
                ->index()
                ->constrained('cash_closes')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_document_kind_admitted CHECK (
                document_kind IN ('receipt', 'cash_close_z')
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_cash_close_link CHECK (
                (document_kind = 'cash_close_z' AND cash_close_id IS NOT NULL)
                OR
                (document_kind = 'receipt' AND cash_close_id IS NULL)
            )
            SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.document_kind IS
            'QUE documento imprime el trabajo: receipt (comprobante fiscal) o cash_close_z (el Z de un cierre de caja).'
            SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.cash_close_id IS
            'El Z que imprime el trabajo. Solo los trabajos de tipo cash_close_z lo tienen, y el CHECK del esquema lo garantiza.'
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE print_jobs DROP CONSTRAINT IF EXISTS print_jobs_cash_close_link');
        DB::statement('ALTER TABLE print_jobs DROP CONSTRAINT IF EXISTS print_jobs_document_kind_admitted');

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_close_id');
            $table->dropColumn('document_kind');
        });
    }
};
