<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M01 — capa de país: catálogo de países, monedas y formatos.
 * El formato del número, de la fecha y del correlativo (`invoice_number_format`)
 * es DATO: vive en `number_formats`, nunca en una constante del código (P6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 50);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->string('symbol', 10)->nullable();
            $table->string('symbol_position', 20)->nullable();
            $table->string('thousand_separator', 5)->nullable();
            $table->string('decimal_separator', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('iso_code', 2)->unique();
            $table->string('name', 100);
            $table->string('locale', 20)->nullable();
            $table->string('timezone', 50)->nullable();
            $table->foreignId('default_currency_id')->nullable()->index()->constrained('currencies')->nullOnDelete();
            $table->string('phone_format', 50)->nullable();
            $table->string('document_id_format', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('number_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->unique()->constrained('countries')->cascadeOnDelete();
            $table->string('date_format', 20)->nullable();
            $table->string('time_format', 20)->nullable();
            $table->string('am_pm', 10)->nullable();
            $table->string('invoice_number_format', 50)->nullable();
            $table->string('phone_format', 50)->nullable();
            $table->string('document_id_format', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_formats');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('currencies');
    }
};
