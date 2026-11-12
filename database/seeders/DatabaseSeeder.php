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
     * Los seeders van en este orden, y el orden NO es cosmetico: es el ORDEN
     * DE DEPENDENCIAS del grafo de llaves foraneas.
     *
     *  1. `LedgerEntryTypeSeeder`: el catalogo de tipos de asiento. El
     *     `LedgerWriter` consulta el catalogo por codigo, asi que sin el
     *     catalogo no hay libro.
     *  2. `LegacyTranslationMapSeeder`: el mapa de traduccion del terminal
     *     legacy. Sin el, el adaptador del sync rechaza cualquier lote real.
     *  3. `TaxonomySeeder`: las monedas, los paises y los formatos numericos.
     *     El perfil fiscal los necesita (`fiscal_profiles.country_id` y
     *     `default_currency_id`).
     *  4. `DemoStoreSeeder`: la tienda de demostracion, con su configuracion y
     *     su cuenta de caja. Las SERIES son por tienda, asi que la tienda tiene
     *     que existir antes de sembrarlas.
     *  5. `ChartOfAccountsSeeder`: el CATALOGO de cuentas y el PLAN de la
     *     tienda de demostracion. El plan es POR TIENDA, asi que la tienda
     *     tiene que existir antes, y el checkout no resuelve el plan sin el.
     *  6. `PaymentMethodSeeder`: el medio de pago de CREDITO y su vinculo por
     *     tienda. El vinculo es por tienda, asi que la tienda tiene que existir
     *     antes.
     *  7. `FiscalProfileSeeder`: el perfil fiscal de cada pais, con sus tipos
     *     de documento, su vocabulario de estados, su catalogo de claves, sus
     *     categorias de IVA con sus tasas y sus dos series.
     *  8. `DemoCatalogSeeder`: el CATALOGO de demostracion: las categorias,
     *     las marcas, las unidades y las cajas, los productos con su ficha de
     *     tienda, el stock que sale de una COMPRA real y el cliente de
     *     demostracion. Depende de la TIENDA y del PERFIL FISCAL (necesita la
     *     tienda donde vender y las categorias fiscales de sus productos): sin
     *     catalogo, una instalacion nueva no puede cobrar nada.
     *  9. `FiscalProviderSeeder`: el canal fiscal por defecto (el proveedor
     *     local de rol dual y su entorno de pruebas). Depende del perfil: el
     *     entorno pertenece al perfil.
     *  10. `OpeningBalancesSeeder`: el saldo de apertura de caja de la tienda,
     *     SIN CERTIFICAR (la certificacion es un acto humano).
     *
     * Las CATEGORIAS DE IVA y las TASAS los siembra `FiscalProfileSeeder` y no
     * `TaxonomySeeder` porque son datos POR PERFIL (`tax_categories.
     * fiscal_profile_id`): sembrarlos desde el seeder de catalogos exigiria que
     * el perfil existiera antes que el pais que lo referencia, y el perfil
     * depende de los paises. Es un ciclo, y el corte lo marca el grafo de
     * llaves foraneas, no el nombre del seeder.
     *
     * Todos son IDEMPOTENTES: sembrar dos veces no duplica ninguna fila.
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
            TaxonomySeeder::class,
            DemoStoreSeeder::class,
            ChartOfAccountsSeeder::class,
            PaymentMethodSeeder::class,
            FiscalProfileSeeder::class,
            DemoCatalogSeeder::class,
            FiscalProviderSeeder::class,
            OpeningBalancesSeeder::class,
        ]);
    }
}
