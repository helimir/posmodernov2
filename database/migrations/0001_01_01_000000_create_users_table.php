<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // La FK a `roles.id` la añade M06: `roles` se crea en M05, que
            // corre después de esta migración.
            $table->foreignId('group_id')->nullable()->index();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->string('mobile', 20)->nullable();
            $table->date('dob')->nullable();
            $table->string('sex', 10)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            // La contraseña se guarda con hash (argon2id/bcrypt), nunca MD5
            // ni en claro como en el legacy.
            $table->string('password');
            $table->unsignedTinyInteger('login_try')->default(0);
            $table->timestamp('last_login')->nullable();
            $table->string('ip', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('user_image', 250)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
