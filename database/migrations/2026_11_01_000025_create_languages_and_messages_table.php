<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M25 — i18n y mensajería: ninguna credencial de proveedor vive en la base.
 * Legacy → destino: `languages` → languages (mismo contrato) ·
 * `language_translations` → language_translations (FK a languages;
 * `lang_value` nullable: una clave sin traducir NO es un error, el legacy la
 * guardaba en NULL y la humanizaba al leer) · `sms_schedule` → sms_messages
 * (outbox con `status`/`sent_at`; el envío real lo hace un job en Fase 2) ·
 * `mail_sms_tag` → message_templates (catálogo de tags `{{…}}`) ·
 * `sms_setting` (eliminado: credenciales en claro) → sms_providers con
 * `credential_env_prefix`: la fila guarda la REFERENCIA al secreto y el
 * secreto se resuelve en runtime desde el entorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->string('code', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('language_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lang_id')->index()->constrained('languages')->cascadeOnDelete();
            $table->string('lang_key', 100);
            $table->text('lang_value')->nullable();
            $table->timestamps();
            $table->unique(['lang_id', 'lang_key']);
        });

        Schema::create('sms_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            // Prefijo de variable de entorno, nunca el secreto.
            $table->string('credential_env_prefix', 100)->unique();
            $table->boolean('supports_scheduling')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->nullable();
            $table->string('tag_name', 128)->unique();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->index()->constrained('stores')->nullOnDelete();
            $table->foreignId('sms_provider_id')->nullable()->index()->constrained('sms_providers')->nullOnDelete();
            $table->foreignId('message_template_id')->nullable()->index()->constrained('message_templates')->nullOnDelete();
            $table->string('mobile_number', 20);
            $table->string('recipient_name', 100)->nullable();
            $table->text('body');
            // Outbox: el estado y el instante de envío son datos; el job que
            // entrega el mensaje llega en Fase 2.
            $table->string('status', 20)->default('pending');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('response')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('sms_providers');
        Schema::dropIfExists('language_translations');
        Schema::dropIfExists('languages');
    }
};
