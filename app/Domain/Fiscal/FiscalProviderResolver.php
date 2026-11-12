<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\Enums\EnvironmentRole;
use App\Domain\Fiscal\Enums\ProviderKind;
use App\Models\FiscalProfileEnvironment;
use App\Models\FiscalProvider;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La RESOLUCION del proveedor fiscal.
 *
 * REGLA que fija esta clase: *el proveedor fiscal de una tienda se RESUELVE,
 * no se hardcodea*. El camino es una cadena de DATOS, y cada eslabon dice
 * cual es el siguiente:
 *
 *  1. la SERIE del documento -> el PERFIL FISCAL y la TIENDA;
 *  2. el PERFIL FISCAL -> el ENTORNO de emision (el de papel `issuer`), segun
 *     el interruptor `fiscal.environment`;
 *  3. el ENTORNO -> el PROVEEDOR, que tiene que estar ACTIVO;
 *  4. el PROVEEDOR -> su TIPO (`ProviderKind`), y del tipo sale la
 *     IMPLEMENTACION.
 *
 * Y EL DOMINIO SOLO RAMIFICA SOBRE `ProviderKind`: el tipo de canal es un
 * dato generico (design §C.1, ADR-0003). Un tipo sin implementacion es un
 * ERROR CON MENSAJE, no un silencio: no hay a que actor externo conectarse, y
 * un actor emulado emitiria comprobantes que no existen.
 */
final class FiscalProviderResolver
{
    /**
     * Los entornos que la resolucion admite.
     */
    private const ENTORNOS = ['testing', 'production'];

    public function __construct(private readonly Connection $connection) {}

    /**
     * Resuelve el proveedor del perfil fiscal de la tienda.
     *
     * @param  int  $documentSeriesId  La serie del DOCUMENTO de la venta: de
     *                                 ella salen el perfil fiscal y la
     *                                 tienda.
     * @param  int|null  $saleId  La VENTA que se esta emitiendo: el canal la
     *                            necesita para reutilizar su numero de
     *                            control.
     *
     * @throws RuntimeException Cuando no hay entorno, el proveedor esta
     *                          inactivo, o su tipo no tiene implementacion.
     */
    public function resolve(int $documentSeriesId, ?int $saleId = null): ElectronicInvoiceProvider
    {
        $serie = $this->serie($documentSeriesId);

        $perfilId = (int) $serie->fiscal_profile_id;
        $tiendaId = (int) $serie->store_id;

        $entorno = $this->entorno($perfilId);
        $proveedor = $this->proveedor($entorno, $perfilId);

        return $this->implementacion($proveedor, $perfilId, $documentSeriesId, $tiendaId, $saleId);
    }

    /**
     * La SERIE del documento: de ella salen el perfil y la tienda.
     */
    private function serie(int $documentSeriesId): object
    {
        $serie = $this->connection->table('fiscal_series')
            ->where('id', $documentSeriesId)
            ->first();

        if ($serie === null) {
            throw new RuntimeException(
                "La serie de documento {$documentSeriesId} no existe: no se puede resolver el canal fiscal."
            );
        }

        return $serie;
    }

    /**
     * El ENTORNO de emision del perfil: el que dice el interruptor.
     *
     * El interruptor `fiscal.environment` elige el entorno: el de pruebas
     * cuando es `testing` y el de produccion cuando es `production`. El
     * entorno es el de PAPEL `issuer`: el papel es el del despliegue (el
     * sistema es emisor, imprenta o ambos) y no un literal del codigo.
     */
    private function entorno(int $perfilId): FiscalProfileEnvironment
    {
        $nombre = $this->entornoNombre();

        $columna = $nombre === 'production' ? 'is_production' : 'is_testing';

        $entorno = FiscalProfileEnvironment::query()
            ->where('fiscal_profile_id', $perfilId)
            ->where('role', EnvironmentRole::Issuer->value)
            ->where($columna, true)
            ->orderBy('id')
            ->first();

        if ($entorno === null) {
            throw new RuntimeException(
                "El perfil fiscal {$perfilId} no tiene ningun entorno de emision para '{$nombre}' "
                .'(papel '.EnvironmentRole::Issuer->value.'): no hay canal por el que emitir.'
            );
        }

        return $entorno;
    }

    /**
     * El NOMBRE del entorno configurado.
     */
    private function entornoNombre(): string
    {
        $nombre = (string) config('fiscal.environment', 'testing');

        if (! in_array($nombre, self::ENTORNOS, true)) {
            throw new RuntimeException(
                "El entorno fiscal '{$nombre}' no existe: solo se admiten "
                .implode(' y ', self::ENTORNOS).'.'
            );
        }

        return $nombre;
    }

    /**
     * El PROVEEDOR del entorno, que tiene que estar ACTIVO.
     */
    private function proveedor(FiscalProfileEnvironment $entorno, int $perfilId): FiscalProvider
    {
        if ($entorno->provider_id === null) {
            throw new RuntimeException(
                "El entorno '{$entorno->code}' del perfil fiscal {$perfilId} no declara proveedor: "
                .'el canal fiscal no se puede resolver.'
            );
        }

        $proveedor = FiscalProvider::query()->find($entorno->provider_id);

        if ($proveedor === null) {
            throw new RuntimeException(
                "El proveedor {$entorno->provider_id} del entorno '{$entorno->code}' no existe."
            );
        }

        if (! $proveedor->is_active) {
            throw new RuntimeException(
                "El proveedor '{$proveedor->code}' del perfil fiscal {$perfilId} esta inactivo: "
                .'por un canal desactivado no se emite.'
            );
        }

        return $proveedor;
    }

    /**
     * La IMPLEMENTACION, deducida del TIPO del proveedor.
     *
     * REGLA que fija este metodo: *un tipo sin implementacion NO se emula*.
     * El dominio solo ramifica sobre `ProviderKind`, que es un dato generico
     * (design §C.1).
     */
    private function implementacion(
        FiscalProvider $proveedor,
        int $perfilId,
        int $documentSeriesId,
        int $storeId,
        ?int $saleId = null,
    ): ElectronicInvoiceProvider {
        $tipo = ProviderKind::tryFrom((string) $proveedor->kind);

        if ($tipo === null) {
            throw new RuntimeException(
                "El proveedor '{$proveedor->code}' declara el tipo '{$proveedor->kind}', que no existe: "
                .'no se sabe que canal es.'
            );
        }

        return match ($tipo) {
            // El ROL DUAL: el sistema es emisor E imprenta, y el numero de
            // control lo genera el generador local.
            ProviderKind::Printer => new LocalPrinterProvider(
                $this->connection,
                new ControlNumberGenerator($this->connection, new NumberSeries($this->connection)),
                $documentSeriesId,
                $perfilId,
                $storeId,
                $saleId,
            ),

            // Un canal EXTERNO no se emula: no hay a que actor conectarse, y
            // un actor de mentira emitiria comprobantes que no existen.
            ProviderKind::Pac, ProviderKind::Portal => throw new RuntimeException(
                "El proveedor '{$proveedor->code}' del perfil fiscal {$perfilId} es de tipo "
                ."'{$proveedor->kind}' y ese canal no tiene implementacion en esta version: "
                .'no se emula a un actor externo.'
            ),
        };
    }
}
