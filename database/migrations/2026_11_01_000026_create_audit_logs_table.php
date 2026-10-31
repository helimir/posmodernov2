<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M26 — auditoría de NEGOCIO: quién hizo qué sobre qué entidad.
 * No es la auditoría fiscal append-only (`fiscal_document_events`, M22): aquélla
 * es inmutable y pertenece a la trazabilidad del comprobante; ésta registra
 * acciones de negocio (actor, acción, entidad, payload) y es consultable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->index()->constrained('stores')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
