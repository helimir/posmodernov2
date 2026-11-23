<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_logs` es APPEND-ONLY: lo que se anoto, se quedo anotado.
 *
 * Por que: la auditoria de negocio es la **prueba** de que un cambio se hizo
 * y quien lo hizo. Si se puede EDITAR o BORRAR una fila, la prueba se puede
 * rehacer a posteriori, y entonces no prueba nada.
 *
 * `fiscal_document_events` ya tiene este trigger (M22). Este es su gemelo
 * para el negocio.
 *
 * Y EL DISEÑO NO ES "QUE NO SE PUEDA": es "QUE NO SE PUEDA SIN QUE SE
 * SEPA". Un borrado o una edicion **fallan**, y el mensaje lo dice.
 *
 * Y LA RETENCION TIENE SU PUERTA: una auditoria que crece sin fin acaba
 *Occupando la base. El comando `ops:audit-purge` borra lo que ya no se
 * guarda, y para poder hacerlo pone en la SESION la marca
 * `app.audit_purge = 'on'`. El trigger solo deja pasar el borrado con esa
 * marca puesta: nadie mas puede borrar, ni por error ni por descuido. Y como
 * la marca es de sesion, se va sola con la transaccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                -- La RETENCION: el unico borrado permitido es el del comando
                -- de purga, y solo si la sesion lo ha pedido.
                IF TG_OP = 'DELETE' AND current_setting('app.audit_purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'audit_logs es append-only: la operacion % no esta permitida', TG_OP
                USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
