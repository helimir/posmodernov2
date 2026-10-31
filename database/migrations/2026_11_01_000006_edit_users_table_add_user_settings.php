<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M06 — el usuario deja de guardar la contraseña en claro y las preferencias en un blob.
 * Legacy → destino: `raw_password` → eliminado (la contraseña nunca se guarda
 * en claro) · `preference` → user_settings · `pass_reset_code`/`reset_code_time`
 * → password_reset_tokens · `group_id` → FK a roles.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('language')->nullable();
            $table->string('base_color')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
        });

        // La columna `group_id` se crea en la migración base de `users`
        // (D18: proyecto verde, se edita la migración base en vez de
        // encadenar ALTERs); la restricción se añade aquí, que es donde
        // `roles` ya existe.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
        });

        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('password_reset_tokens');
    }
};
