<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M42 — el PREFIJO de las credenciales del proveedor fiscal.
 *
 * EL PROBLEMA que esta migracion arregla: *`fiscal_providers.credentials_ref`
 * es texto libre y NADIE lo resuelve*. La fila puede decir cualquier cosa
 * ("seniat", "vault://imprenta") y no hay codigo que la convierta en algo
 * usable: no hay equivalente a `SmsProvider::credential()`.
 *
 * LA COLUMNA: `fiscal_providers.credential_env_prefix`, ANULABLE, y es el
 * PREFIJO de la variable de entorno donde vive el secreto — la MISMA
 * convencion que `sms_providers.credential_env_prefix` (M25). La fila declara
 * el PREFIX y el codigo resuelve `PREFIX_` + el nombre de la variable.
 *
 * ES ANULABLE A PROPOSITO: el canal LOCAL no tiene credenciales (no hay a
 * quien autenticarse), asi que su prefijo en `null` es una configuracion
 * LEGITIMA, no un olvido. Un canal EXTERNO sin prefijo es un error de
 * CONFIGURACION, y lo dice quien lo usa.
 *
 * Y NO ES UNICA: la fila del proveedor esta acotada por su perfil fiscal
 * (`fiscal_profile_id`, `code`), y el mismo namespace puede servir a dos
 * perfiles (el mismo proveedor en dos regimenes). Unica seria una regla
 * inventada aqui.
 *
 * `credentials_ref` SE QUEDA: puede tener otros usos, y borrarla obligaria a
 * migrar datos. Esta unidad NO lo usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_providers', function (Blueprint $table): void {
            // El prefijo de la variable de entorno, nunca el secreto.
            $table->string('credential_env_prefix', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_providers', function (Blueprint $table): void {
            $table->dropColumn('credential_env_prefix');
        });
    }
};
