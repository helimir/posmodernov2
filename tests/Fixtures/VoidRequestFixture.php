<?php

namespace Tests\Fixtures;

use App\Domain\Accounting\CashRegisterService;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de la solicitud de anulacion: el cajero que PIDE, el
 * contador que ANULA y una venta pagada.
 *
 * REGLA que fija este fixture: *el reparto de permisos es el del negocio*: el
 * cajero cobra y PIDE la anulacion (pero no anula), y el contador lee y
 * ANULA (pero no pide: no le hace falta, porque puede deshacer la venta
 * directamente).
 *
 * Y EL CAJERO ABRE LA CAJA DEL DIA: el escenario de la caja la trae
 * abierta, y el contador entra despues y sin abrirla, porque una tienda
 * tiene UNA caja por fecha de negocio.
 */
final class VoidRequestFixture
{
    /**
     * El ESCENARIO: la tienda de demostracion, el cajero y el contador.
     *
     * @return array{store_id: int, escenario: array<string, mixed>, cajero: User, contador: User}
     */
    public static function escenario(): array
    {
        $escenario = PosFixture::escenario();

        PermissionFixture::catalogo();

        $rolCajero = PermissionFixture::rol('cajero-solicitud-'.uniqid(), [
            'sales.read',
            'sales.create',
            'sales.void_request',
        ]);

        $rolContador = PermissionFixture::rol('contador-solicitud-'.uniqid(), [
            'sales.read',
            'sales.void',
        ]);

        $cajero = PermissionFixture::usuarioConCaja($rolCajero, $escenario['store_id']);
        $contador = PermissionFixture::usuario($rolContador, $escenario['store_id']);

        return [
            'store_id' => (int) $escenario['store_id'],
            'escenario' => $escenario,
            'cajero' => $cajero,
            'contador' => $contador,
        ];
    }

    /**
     * Una VENTA pagada de la tienda, con su numero.
     *
     * Es una venta SIN comprobante fiscal: lo que se prueba con ella es el
     * flujo de la solicitud, no la emision.
     *
     * Y SU FECHA ES LA DE NEGOCIO DE LA TIENDA: la fecha del servidor es
     * otro dia, y una venta de otro dia que la caja no cubre haria que la
     * guarda del dia cerrado comparase dos dias distintos.
     */
    public static function venta(int $storeId, string $invoiceNo): Sale
    {
        return Sale::query()->create([
            'store_id' => $storeId,
            'invoice_no' => $invoiceNo,
            'sale_date' => PosFixture::businessDate($storeId),
            'status' => 'paid',
        ]);
    }

    /**
     * La CAJA del dia de la tienda.
     *
     * Y LA FECHA LA RESUELVE EL FIXTURE, NO EL TEST: la caja esta abierta
     * con la FECHA DE NEGOCIO de la tienda, y un test que nombrara la fecha
     * por su cuenta podria usar la del servidor (que es otro dia) y no
     * encontraria nada. Preguntar aqui es no poder equivocarse.
     */
    public static function cajaDelDia(int $storeId): object
    {
        $caja = DB::table('cash_registers')
            ->where('store_id', $storeId)
            ->where('business_date', PosFixture::businessDate($storeId))
            ->first();

        expect($caja)->not->toBeNull('La caja del dia no existe: el escenario no la abrio.');

        return $caja;
    }

    /**
     * CIERRA la caja del dia, con el saldo contado que se le pasa.
     *
     * El cierre sella el Z: es el cierre HECHO.
     */
    public static function cierraLaCaja(int $cajaId, string $contado, int $actorId): void
    {
        (new CashRegisterService(DB::connection()))->close($cajaId, $contado, $actorId);
    }

    /**
     * REABRE la caja del dia, con su motivo.
     *
     * El Z anterior NO se borra: se queda marcado.
     */
    public static function reabreLaCaja(int $cajaId, string $motivo, int $actorId): void
    {
        (new CashRegisterService(DB::connection()))->reopen($cajaId, $motivo, $actorId);
    }
}
