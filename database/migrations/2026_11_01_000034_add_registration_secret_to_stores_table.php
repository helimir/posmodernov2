<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M34 — el SECRETO DE REGISTRO del agente LAN, POR TIENDA.
 *
 * `POST /api/print-agents/register` es el unico endpoint PUBLICO de la API
 * del agente LAN: no hay token todavia, porque el token es lo que el
 * registro ENTREGA. Su unica defensa era el interruptor
 * `PRINT_LAN_AGENT_ENABLED`, y con el interruptor encendido cualquiera con
 * acceso de red a la API podria registrar un agente para CUALQUIER tienda y
 * tirar de sus tickets, que llevan BYTES FISCALES (el comprobante y su QR).
 *
 * La columna guarda el HASH (SHA-256) del secreto de registro de la tienda,
 * NUNCA el secreto en claro: el secreto lo emite
 * `php artisan ops:print-agent-registration-secret {store}` y se muestra una
 * sola vez, para entregarlo a quien instala el agente.
 *
 * Es NULLABLE a proposito: una tienda que no imprime por LAN no tiene
 * secreto, y una tienda SIN secreto NO PUEDE registrar agentes (el registro
 * responde 403 y dice como configurarlo). El hueco se ve; no se inventa un
 * valor por defecto que dejara la puerta abierta.
 *
 * El CHECK anade la garantia que el codigo solo no puede dar: la columna
 * admite un digest SHA-256 (64 caracteres hexadecimales) o NULL, y NADA mas.
 * Como el secreto en claro es base64url, es FISICAMENTE IMPOSIBLE que quepa
 * en la columna: el error no depende de la disciplina del que escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('print_agent_registration_secret', 255)->nullable();
        });

        DB::statement(
            "ALTER TABLE stores ADD CONSTRAINT stores_print_agent_registration_secret_hash CHECK (print_agent_registration_secret IS NULL OR print_agent_registration_secret ~ '^[0-9a-f]{64}$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_print_agent_registration_secret_hash');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('print_agent_registration_secret');
        });
    }
};
