<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La BASE de las pantallas de reportes: el contexto, el ambito y el periodo.
 *
 * REGLA que fija esta clase: *un reporte se lee con el CONTEXTO de la
 * peticion*. La tienda es la del contexto, el ambito es el del ROL
 * (`PosContext::scopeIds()`) y la moneda es la de la tienda. Y el PERIODO son
 * DOS DATOS que llegan en la peticion: el reporte no se inventa el dia.
 *
 * Y EL PERIODO POR DEFECTO es el MES de la tienda: el reporte se abre con lo
 * que tiene sentido leer, y el que quiere otro periodo lo pide. El dia del
 * reporte es el dia de la TIENDA (su zona horaria), no el del servidor.
 *
 * Y UN PERIODO INVALIDO es un error con su motivo: el periodo se valida, y
 * un periodo al reves no se enseña como una cuenta vacia.
 */
abstract class ReportController extends Controller
{
    public function __construct(protected readonly Connection $connection) {}

    /**
     * El CONTEXTO de la peticion.
     *
     * Se crea aqui y no en el constructor: el controlador puede sobrevivir a
     * una peticion, y un contexto guardado en la instancia seria el contexto
     * de la peticion ANTERIOR.
     */
    protected function contexto(Request $request): PosContext
    {
        return new PosContext($this->connection, $request->user()?->id);
    }

    /**
     * El AMBITO del reporte: las tiendas del ROL de quien pregunta.
     */
    protected function ambito(Request $request): ReportScope
    {
        return ReportScope::fromContext($this->contexto($request));
    }

    /**
     * El PERIODO pedido, con el mes de la tienda por defecto.
     *
     * Y UN PERIODO INVALIDO es un 422 con su motivo: el periodo se valida, y
     * un periodo al reves no se enseña como una cuenta vacia sin explicar
     * por que.
     */
    protected function periodo(Request $request): ReportPeriod
    {
        $negocio = $this->contexto($request)->businessDate();

        $desde = $this->parametro($request, 'from')
            ?? $negocio->modify('first day of this month')->format('Y-m-d');

        $hasta = $this->parametro($request, 'to')
            ?? $negocio->format('Y-m-d');

        try {
            return ReportPeriod::between($desde, $hasta);
        } catch (RuntimeException $fallo) {
            abort(422, $fallo->getMessage());
        }
    }

    /**
     * Un PARAMETRO de la peticion, limpio.
     */
    private function parametro(Request $request, string $clave): ?string
    {
        $valor = $request->query($clave);

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Las PROPS que comparten todas las pantallas de reportes.
     *
     * @return array{
     *     period: array{from: string, to: string},
     *     store: array{id: int, name: string, code_name: string|null},
     *     currency: array<string, mixed>,
     *     scope: array{unlimited: bool, stores: int}
     * }
     */
    protected function props(Request $request, ReportPeriod $periodo): array
    {
        $contexto = $this->contexto($request);

        try {
            $tienda = $contexto->store();
            $moneda = $contexto->currency();
        } catch (RuntimeException $fallo) {
            // Sin tienda activa o sin moneda la pagina NO abre: no se sirve
            // una pantalla de una tienda que no existe.
            abort(409, $fallo->getMessage());
        }

        $ambito = $contexto->scopeIds();

        return [
            'period' => $periodo->toArray(),
            'store' => [
                'id' => (int) $tienda->id,
                'name' => (string) $tienda->name,
                'code_name' => $tienda->code_name,
            ],
            'currency' => $moneda,
            'scope' => [
                'unlimited' => $ambito === null,
                'stores' => $ambito === null ? $this->totalDeTiendas() : count($ambito),
            ],
        ];
    }

    /**
     * CUANTAS tiendas hay en el sistema: el dato que se ensena cuando el
     * ambito son todas.
     */
    private function totalDeTiendas(): int
    {
        return (int) $this->connection->table('stores')->count();
    }
}
