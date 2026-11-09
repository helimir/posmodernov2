<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los CATALOGOS BASE de la capa de pais: las MONEDAS, los PAISES y los
 * FORMATOS NUMERICOS.
 *
 * Son la fila mas simple de la capa y, a la vez, la que mas trabaja: el motor
 * fiscal, el de impuestos, el de numeracion y el de formatos son DATO, no
 * codigo (ADR-0003). Cambiar de pais es INSERTAR FILAS, y estas son las
 * primeras.
 *
 * LO QUE HAY AQUI Y LO QUE NO:
 *
 *  - La MONEDA (codigo ISO 4217, decimales, simbolo y separadores) es un hecho
 *    objetivo: no depende de ninguna confirmacion legal, asi que no lleva
 *    estado de evidencia (su tabla no lo tiene, y no hace falta).
 *  - El PAIS (codigo ISO 3166-1, locale, zona horaria) tambien es un hecho
 *    objetivo. El `timezone` es el del PAIS, no el del servidor: el legacy
 *    escribia la zona horaria en `_init.php` y la cambiaba desde la pantalla
 *    de tienda, con lo que dos tiendas en paises distintos compartian reloj.
 *  - El FORMATO NUMERICO (fecha, hora y formato del correlativo) es la
 *    convencion del pais: por eso vive en una fila y no en una constante del
 *    codigo (0.2.1-T).
 *
 * Las CATEGORIAS DE IVA y sus TASAS NO viven aqui: son datos POR PERFIL
 * (`tax_categories.fiscal_profile_id`), y el perfil fiscal depende de este
 * catalogo. Sembrarlas aqui exigiria que el perfil existiera antes que el
 * pais que lo referencia: un ciclo. Las siembra `FiscalProfileSeeder`, que
 * es su dueno natural.
 *
 * Es IDEMPOTENTE: el `upsert` se apoya en las restricciones unicas (`code`,
 * `iso_code`, `country_id`), asi que sembrar dos veces actualiza las mismas
 * filas y no duplica ninguna.
 */
class TaxonomySeeder extends Seeder
{
    /**
     * Siembra los catalogos base.
     */
    public function run(): void
    {
        $now = now();

        $this->monedas($now);
        $this->paises($now);
        $this->formatos($now);
    }

    /**
     * Las CINCO MONEDAS de los cinco paises.
     *
     * El numero de DECIMALES es el dato que mas importa: el motor de
     * impuestos redondea con la escala de la MONEDA (`TaxContext`), y una
     * moneda con los decimales mal sembrados redondea el IVA al revés.
     *
     * @param  Carbon  $now
     */
    private function monedas($now): void
    {
        $monedas = [];

        foreach ($this->catalogoDeMonedas() as $moneda) {
            $monedas[] = [
                'code' => $moneda['code'],
                'name' => $moneda['name'],
                'decimals' => $moneda['decimals'],
                'symbol' => $moneda['symbol'],
                'symbol_position' => $moneda['symbol_position'],
                'thousand_separator' => $moneda['thousand_separator'],
                'decimal_separator' => $moneda['decimal_separator'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('currencies')->upsert(
            $monedas,
            ['code'],
            ['name', 'decimals', 'symbol', 'symbol_position', 'thousand_separator', 'decimal_separator', 'updated_at']
        );
    }

    /**
     * Los CINCO PAISES, cada uno con su moneda por defecto.
     *
     * @param  Carbon  $now
     */
    private function paises($now): void
    {
        $idsDeMoneda = DB::table('currencies')->pluck('id', 'code');

        $paises = [];

        foreach ($this->catalogoDePaises() as $pais) {
            $paises[] = [
                'iso_code' => $pais['iso_code'],
                'name' => $pais['name'],
                'locale' => $pais['locale'],
                'timezone' => $pais['timezone'],
                'default_currency_id' => $idsDeMoneda[$pais['moneda']] ?? null,
                'phone_format' => $pais['phone_format'],
                'document_id_format' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('countries')->upsert(
            $paises,
            ['iso_code'],
            ['name', 'locale', 'timezone', 'default_currency_id', 'phone_format', 'document_id_format', 'updated_at']
        );
    }

    /**
     * Un FORMATO NUMERICO por pais: la fecha, la hora y el formato del
     * correlativo.
     *
     * El `document_id_format` se deja a NULL a proposito: el formato oficial
     * del identificador fiscal de cada pais (RIF, RUT, RFC, NIT, RUC) no esta
     * transcrito, y un patron inventado validaria documentos mal formados.
     *
     * @param  Carbon  $now
     */
    private function formatos($now): void
    {
        $idsDePais = DB::table('countries')->pluck('id', 'iso_code');

        $formatos = [];

        foreach ($this->catalogoDePaises() as $pais) {
            $formatos[] = [
                'country_id' => $idsDePais[$pais['iso_code']] ?? null,
                'date_format' => $pais['date_format'],
                'time_format' => $pais['time_format'],
                'am_pm' => null,
                'invoice_number_format' => $pais['invoice_number_format'],
                'phone_format' => $pais['phone_format'],
                'document_id_format' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('number_formats')->upsert(
            $formatos,
            ['country_id'],
            ['date_format', 'time_format', 'am_pm', 'invoice_number_format', 'phone_format', 'document_id_format', 'updated_at']
        );
    }

    /**
     * Las MONEDAS: codigo, nombre, decimales, simbolo y separadores.
     *
     * @return list<array{code: string, name: string, decimals: int, symbol: string, symbol_position: string, thousand_separator: string, decimal_separator: string}>
     */
    private function catalogoDeMonedas(): array
    {
        return [
            ['code' => 'VES', 'name' => 'Bolivar soberano', 'decimals' => 2, 'symbol' => 'Bs.', 'symbol_position' => 'before', 'thousand_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'CLP', 'name' => 'Peso chileno', 'decimals' => 0, 'symbol' => '$', 'symbol_position' => 'before', 'thousand_separator' => '.', 'decimal_separator' => ','],
            ['code' => 'MXN', 'name' => 'Peso mexicano', 'decimals' => 2, 'symbol' => '$', 'symbol_position' => 'before', 'thousand_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'COP', 'name' => 'Peso colombiano', 'decimals' => 0, 'symbol' => '$', 'symbol_position' => 'before', 'thousand_separator' => '.', 'decimal_separator' => ','],
            ['code' => 'PAB', 'name' => 'Balboa', 'decimals' => 2, 'symbol' => 'B/.', 'symbol_position' => 'before', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        ];
    }

    /**
     * Los PAISES: codigo ISO, nombre, locale, zona horaria, moneda por defecto
     * y los formatos que le son propios.
     *
     * @return list<array{iso_code: string, name: string, locale: string, timezone: string, moneda: string, phone_format: string, date_format: string, time_format: string, invoice_number_format: string}>
     */
    private function catalogoDePaises(): array
    {
        return [
            [
                'iso_code' => 'VE',
                'name' => 'Venezuela',
                'locale' => 'es-VE',
                'timezone' => 'America/Caracas',
                'moneda' => 'VES',
                'phone_format' => '+58',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => 'HH:mm:ss',
                // El correlativo del emisor lleva delante la palabra "serie"
                // (art. 20), asi que el formato la incluye.
                'invoice_number_format' => 'serie-00000000',
            ],
            [
                'iso_code' => 'CL',
                'name' => 'Chile',
                'locale' => 'es-CL',
                'timezone' => 'America/Santiago',
                'moneda' => 'CLP',
                'phone_format' => '+56',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => 'HH:mm:ss',
                'invoice_number_format' => '000000000',
            ],
            [
                'iso_code' => 'MX',
                'name' => 'Mexico',
                'locale' => 'es-MX',
                'timezone' => 'America/Mexico_City',
                'moneda' => 'MXN',
                'phone_format' => '+52',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => 'HH:mm:ss',
                'invoice_number_format' => '000000000',
            ],
            [
                'iso_code' => 'CO',
                'name' => 'Colombia',
                'locale' => 'es-CO',
                'timezone' => 'America/Bogota',
                'moneda' => 'COP',
                'phone_format' => '+57',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => 'HH:mm:ss',
                'invoice_number_format' => '000000000',
            ],
            [
                'iso_code' => 'PA',
                'name' => 'Panama',
                'locale' => 'es-PA',
                'timezone' => 'America/Panama',
                'moneda' => 'PAB',
                'phone_format' => '+507',
                'date_format' => 'DD/MM/YYYY',
                'time_format' => 'HH:mm:ss',
                'invoice_number_format' => '000000000',
            ],
        ];
    }
}
