<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El CANAL FISCAL por defecto: el proveedor local y su entorno.
 *
 * QUE SIEMBRA, Y POR QUE:
 *
 *  - EL PROVEEDOR LOCAL (`kind = 'printer'`): el camino de ROL DUAL, en el
 *    que el sistema es emisor E imprenta. Es el unico canal que se puede
 *    sembrar sin afirmar una conexion que no existe: no hay ni una URL, ni
 *    unas credenciales, ni un actor externo. El numero de control lo genera
 *    el generador local;
 *  - SU ENTORNO, de PAPEL `issuer` y de MARCA de pruebas: el entorno decide
 *    que despliegue se usa, y el papel dice el papel del sistema en ese
 *    despliegue.
 *
 * LO QUE NO SIEMBRA, Y POR QUE:
 *
 *  - NO se siembra un entorno de PRODUCCION. No hay autoridad conectada: un
 *    entorno de produccion apuntando al proveedor local seria una MENTIRA,
 *    y pedir produccion tiene que dar un error claro. El operador siembra su
 *    despliegue de produccion cuando tenga el canal de verdad;
 *  - NO se siembran proveedores de otros tipos (`pac`, `portal`): no hay a
 *    que actor externo conectarse, y sembrarlos afirmaria un canal que nadie
 *    ha verificado.
 *
 * Es IDEMPOTENTE: los `upsert` se apoyan en las restricciones unicas de cada
 * tabla.
 */
class FiscalProviderSeeder extends Seeder
{
    /**
     * El codigo del proveedor local.
     */
    private const CODIGO_PROVEEDOR = 'local_printer';

    /**
     * El codigo del entorno local de pruebas.
     */
    private const CODIGO_ENTORNO = 'local_testing';

    /**
     * El papel del entorno sembrado: el sistema EMITE.
     */
    private const PAPEL = 'issuer';

    /**
     * El tipo del proveedor local: el de rol dual, el que hace de imprenta.
     */
    private const TIPO = 'printer';

    /**
     * Siembra el proveedor local y su entorno de pruebas.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->perfiles() as $perfilId) {
            $proveedorId = $this->proveedor((int) $perfilId, $now);

            $this->entorno((int) $perfilId, $proveedorId, $now);
        }
    }

    /**
     * Los PERFILES sembrados: el canal local se sembrea en todos, porque el
     * rol dual no es una rareza de un pais: es un papel del despliegue.
     *
     * @return list<int>
     */
    private function perfiles(): array
    {
        return DB::table('fiscal_profiles')->orderBy('id')->pluck('id')->all();
    }

    /**
     * El PROVEEDOR LOCAL del perfil.
     */
    private function proveedor(int $perfilId, Carbon $now): int
    {
        DB::table('fiscal_providers')->upsert([
            [
                'fiscal_profile_id' => $perfilId,
                'code' => self::CODIGO_PROVEEDOR,
                'name' => 'Imprenta local del sistema',
                'kind' => self::TIPO,
                // El canal local no tiene donde esperar una peticion, ni
                // quien le pida una credencial.
                'base_url' => null,
                'auth_scheme' => null,
                'credentials_ref' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['fiscal_profile_id', 'code'], ['name', 'kind', 'base_url', 'auth_scheme', 'credentials_ref', 'is_active', 'updated_at']);

        return (int) DB::table('fiscal_providers')
            ->where('fiscal_profile_id', $perfilId)
            ->where('code', self::CODIGO_PROVEEDOR)
            ->value('id');
    }

    /**
     * El ENTORNO local de pruebas del perfil.
     */
    private function entorno(int $perfilId, int $proveedorId, Carbon $now): void
    {
        DB::table('fiscal_profile_environments')->upsert([
            [
                'fiscal_profile_id' => $perfilId,
                'code' => self::CODIGO_ENTORNO,
                'label' => 'Entorno local de pruebas',
                'role' => self::PAPEL,
                'provider_id' => $proveedorId,
                'base_url' => null,
                'auth_scheme' => null,
                'is_testing' => true,
                // NO hay entorno de produccion: no hay autoridad conectada.
                'is_production' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['fiscal_profile_id', 'code'], ['label', 'role', 'provider_id', 'base_url', 'auth_scheme', 'is_testing', 'is_production', 'updated_at']);
    }
}
