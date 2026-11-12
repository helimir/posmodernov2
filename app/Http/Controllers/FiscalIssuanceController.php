<?php

namespace App\Http\Controllers;

use App\Domain\Fiscal\FiscalIssuanceRunner;
use App\Domain\Fiscal\ValueObjects\FiscalIssuanceOutcome;
use App\Support\Fiscal\StoreAccess;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La EMISION FISCAL de una venta: el REINTENTO.
 *
 * REGLA que fija esta clase: *un reintento de emision NO puede hacer perder
 * una venta*. El reintento usa el mismo camino que la emision (el
 * `FiscalIssuanceRunner`), que reutiliza la reserva y no quema numeros: si
 * el canal vuelve a fallar, la venta sigue existiendo y la respuesta dice
 * POR QUE.
 *
 * Y EL INTERRUPTOR APAGADO ES UNA EMISION QUE NO OCURRE: con el interruptor
 * apagado no se entra en T2, ni en el cobro ni en el reintento. Es el modo de
 * puesta en marcha.
 *
 * Y LA VENTA ES DE UNA TIENDA: un reintento sobre una venta de otra tienda
 * es un 404, como el comprobante.
 */
final class FiscalIssuanceController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalIssuanceRunner $emision,
        private readonly StoreAccess $access,
    ) {}

    /**
     * El REINTENTO de la emision fiscal de una venta.
     *
     * Responde 200 con `{ fiscal }`: el estado fiscal de la venta DESPUES del
     * reintento, con el MISMO vocabulario que la respuesta del cobro.
     */
    public function reintentar(Request $request, int $sale): JsonResponse
    {
        $model = $this->connection->table('sales')->where('id', $sale)->first();

        abort_if($model === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $model->store_id),
            404
        );

        if (! config('fiscal.emission_enabled')) {
            return response()->json(['fiscal' => FiscalIssuanceOutcome::noEmitido()->aArray()]);
        }

        $resultado = $this->emision->issue($sale, $request->user()?->id);

        return response()->json(['fiscal' => $resultado->aArray()]);
    }
}
