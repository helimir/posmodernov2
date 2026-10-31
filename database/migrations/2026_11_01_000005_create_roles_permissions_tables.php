<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M05 — el blob `user_group.permission` se normaliza en filas.
 * Legacy → destino: `user_group` → roles · `user_group.permission` →
 * permissions + role_permissions · `shortcut_links` → dashboard_shortcuts ·
 * `shortcut_links.permission_slug` → dashboard_shortcuts.permission_id (FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('module', 64);
            $table->string('action', 32);
            $table->string('label', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->index()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->index()->constrained('permissions')->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('role_store_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->index()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            $table->unique(['role_id', 'store_id']);
            $table->timestamps();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->index()->constrained('stores')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->timestamps();
        });

        Schema::create('dashboard_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100)->nullable();
            $table->text('href')->nullable();
            $table->string('target', 100)->default('_self');
            $table->string('title');
            $table->string('icon', 50)->default('');
            $table->text('thumbnail')->nullable();
            $table->foreignId('permission_id')->nullable()->index()->constrained('permissions')->nullOnDelete();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_shortcuts');
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('role_store_scopes');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
