<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * El esquema lo dan las MIGRACIONES y los datos los SEEDERS: el
     * `migrate:fresh --seed` es el camino de la instalacion.
     *
     * Los seeders van en este orden:
     *
     *  1. `LedgerEntryTypeSeeder`: el catalogo de tipos de asiento. El
     *     `LedgerWriter` consulta el catalogo por codigo, asi que sin el
     *     catalogo no hay libro.
     *  2. `LegacyTranslationMapSeeder`: el mapa de traduccion del terminal
     *     legacy. Sin el, el adaptador del sync rechaza cualquier lote real.
     *
     * Los dos son IDEMPOTENTES: sembrar dos veces no duplica ninguna fila.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // El usuario de prueba: se crea SOLO si no esta, para que sembrar
        // dos veces no choque con la unicidad del correo.
        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call([
            LedgerEntryTypeSeeder::class,
            LegacyTranslationMapSeeder::class,
        ]);
    }
}
