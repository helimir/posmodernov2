<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El ARQUEO POR DENOMINACIONES: el catalogo de las piezas de una tienda, el
 * desglose esperado de un importe, el conteo real y la diferencia por pieza.
 *
 * REGLA que fija esta clase: *el cajero cuenta PIEZAS, y el sistema compara
 * las piezas contra el importe*. El cajero no teclea una cifra: teclea
 * cuantas piezas hay de cada denominacion, y el sistema las suma y las
 * contrasta con el desglose del importe esperado. Esa es la diferencia de
 * fondo con el legacy, que solo sabia pedir un importe con dos decimales.
 *
 * EL DESGLOSE ES DE MAYOR A MENOR, Y NO ES SIEMPRE EL MINIMO NUMERO DE
 * PIEZAS. El clasico es 1-3-4-6 con un 5: para un importe de 8, el desglose
 * de mayor a menor da 6+1+1 (tres piezas) y el minimo es 4+4 (dos piezas).
 * aqui gana el PRIMER criterio: un cajero cuenta de mayor a menor, y el
 * arqueo tiene que ensenarle la cuenta que va a hacer, no la cuenta
 *matematicamente optima. Por eso el desglose de este servicio es
 * codigo, no una busqueda del minimo.
 *
 * Y SI EL IMPORTE NO SE PUEDE FORMAR, el desglose dice QUE FALTA: "no hay
 * una denominacion de 0.01: el importe no se puede formar con las
 * denominaciones de esta tienda". Un arqueo que no se puede desglosar es un
 * arqueo que hay que decir, no uno que hay que inventar.
 *
 * LOS IMPORTES viajan como TEXTO decimal: un flotante no es un dinero.
 */
final readonly class CashDenominations
{
    /**
     * La pieza es un BILLETE.
     */
    public const KIND_BILLETE = 'billete';

    /**
     * La pieza es una MONEDA.
     */
    public const KIND_MONEDA = 'moneda';

    public function __construct(private Connection $connection) {}

    /**
     * El CATALOGO de una tienda: las denominaciones activas, de MAYOR a
     * MENOR.
     *
     * REGLA que fija este metodo: *el catalogo se LEE de las filas*. Los
     * valores de las piezas son DATO del negocio (son del pais que emite la
     * moneda), asi que estan en `cash_denominations` y no en el codigo.
     *
     * Y EL ORDEN es el de la cuenta: de mayor a menor, que es como cuenta un
     * cajero.
     *
     * @param  int  $storeId  La tienda de la caja.
     * @param  int|null  $currencyId  La moneda de la tienda; `null` significa
     *                                "la que sea", y se usa solo cuando la
     *                                tienda no declara ninguna.
     * @return list<array{id: int, currency_id: int, value: string, kind: string}>
     */
    public function catalog(int $storeId, ?int $currencyId = null): array
    {
        $consulta = $this->connection->table('cash_denominations')
            ->where('store_id', $storeId)
            ->where('is_active', true);

        if ($currencyId !== null) {
            $consulta->where('currency_id', $currencyId);
        }

        $filas = $consulta
            ->orderByDesc('value')
            ->orderBy('id')
            ->get(['id', 'currency_id', 'value', 'kind']);

        $catalogo = [];

        foreach ($filas as $fila) {
            $catalogo[] = [
                'id' => (int) $fila->id,
                'currency_id' => (int) $fila->currency_id,
                'value' => PosAmount::text($fila->value),
                'kind' => (string) $fila->kind,
            ];
        }

        return $catalogo;
    }

    /**
     * EL DESGLOSE ESPERADO de un importe: las piezas de cada denominacion.
     *
     * REGLA que fija este metodo: *el desglose es de mayor a menor*. Cada
     * denominacion se toma tantas veces como quepa en lo que queda, y se
     * sigue con la siguiente. No se busca el minimo numero de piezas: el
     * cajero cuenta de mayor a menor, y el arqueo ensena esa cuenta.
     *
     * Y SI NO SE PUEDE FORMAR, se devuelve el RESTO y el AVISO de que falta:
     * el arqueo de un importe que el catalogo de la tienda no puede
     * desglosar no es un arqueo, es una adivinanza.
     *
     * @param  string  $amount  El importe a desglosar. Texto decimal.
     * @param  list<array{id: int, currency_id: int, value: string, kind: string}>  $catalogo
     * @return array{pieces: list<array{id: int, value: string, kind: string, count: int}>, total: string, residual: string|null, missing: string|null}
     */
    public function expected(string $amount, array $catalogo): array
    {
        $total = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        if ($catalogo === []) {
            return [
                'pieces' => [],
                'total' => '0.0000',
                'residual' => PosAmount::text($amount),
                'missing' => 'La tienda no tiene denominaciones de efectivo: '
                    .'el importe no se puede desglosar en piezas.',
            ];
        }

        $restante = PosAmount::decimal($amount);
        $piezas = [];

        foreach ($catalogo as $denominacion) {
            $valor = PosAmount::decimal($denominacion['value']);

            // Una pieza de valor CERO no divide: se cuenta como una pieza que
            // no aporta nada.
            $cuantas = $valor->isPositive()
                ? $restante->dividedBy($valor, 0, RoundingMode::FLOOR)
                : BigDecimal::zero();

            $restante = $restante->minus($valor->multipliedBy($cuantas));
            $total = $total->plus($valor->multipliedBy($cuantas));

            $piezas[] = [
                'id' => $denominacion['id'],
                'value' => $denominacion['value'],
                'kind' => $denominacion['kind'],
                'count' => (int) $cuantas->toInt(),
            ];
        }

        $residual = null;
        $faltante = null;

        if (! $restante->isZero()) {
            $residual = (string) $restante->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

            $faltante = 'No hay una denominacion de '.PosAmount::legible($residual).': '
                .'el importe no se puede formar con las denominaciones de esta tienda.';
        }

        return [
            'pieces' => $piezas,
            'total' => (string) $total->toScale(PosAmount::SCALE, RoundingMode::HALF_UP),
            'residual' => $residual,
            'missing' => $faltante,
        ];
    }

    /**
     * El TOTAL CONTADO: la suma de las piezas que el cajero conto.
     *
     * REGLA que fija este metodo: *el saldo contado es el SUMA de las
     * piezas*. El cajero no teclea una cifra: cuenta, y el sistema suma. Por
     * eso el total sale de aqui y no de un campo del formulario: si el
     * formulario trajera tambien una cifra, habria dos verdades.
     *
     * Y UNA PIEZA QUE NO ES DE LA TIENDA es un ERROR: el conteo de una caja
     * no puede traer piezas de otro catalogo.
     *
     * @param  array<int, int>  $conteo  Las piezas contadas, por id de
     *                                   denominacion.
     * @param  list<array{id: int, currency_id: int, value: string, kind: string}>  $catalogo
     *
     * @throws RuntimeException Cuando el conteo trae una pieza que no es del
     *                          catalogo de la tienda.
     */
    public function countedTotal(array $conteo, array $catalogo): string
    {
        $total = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        foreach ($conteo as $id => $cuantas) {
            $denominacion = $this->busca($catalogo, (int) $id);

            if ($denominacion === null) {
                throw new RuntimeException(
                    "La denominacion {$id} no es del catalogo de esta tienda: "
                    .'el conteo de la caja no puede traer piezas que la tienda no tiene.'
                );
            }

            $total = $total->plus(
                PosAmount::decimal($denominacion['value'])->multipliedBy((int) $cuantas)
            );
        }

        return (string) $total->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * LAS DIFERENCIAS por denominacion: que pieza sobra o falta.
     *
     * Solo se devuelven las que NO cuadran: una diferencia de cero no es una
     * diferencia, y una lista de ceros no dice nada.
     *
     * @param  list<array{id: int, value: string, kind: string, count: int}>  $esperadas
     * @param  array<int, int>  $conteo
     * @return list<array{id: int, value: string, kind: string, expected: int, counted: int, delta: int}>
     */
    public function differences(array $esperadas, array $conteo): array
    {
        $diferencias = [];

        foreach ($esperadas as $esperada) {
            $contadas = (int) ($conteo[$esperada['id']] ?? 0);
            $delta = $contadas - $esperada['count'];

            if ($delta === 0) {
                continue;
            }

            $diferencias[] = [
                'id' => $esperada['id'],
                'value' => $esperada['value'],
                'kind' => $esperada['kind'],
                'expected' => $esperada['count'],
                'counted' => $contadas,
                'delta' => $delta,
            ];
        }

        return $diferencias;
    }

    /**
     * DESCRIBE una diferencia: "sobran 2 billetes de 50".
     *
     * REGLA que fija este metodo: *la diferencia NOMBRA la pieza*. Un
     * descuadre de 100 no dice por que pasa; "sobran dos billetes de 50" si.
     *
     * @param  array{id: int, value: string, kind: string, expected: int, counted: int, delta: int}  $diferencia
     */
    public function describeDifference(array $diferencia): string
    {
        $cuantas = abs($diferencia['delta']);
        $valor = PosAmount::legible($diferencia['value']);

        $singular = $diferencia['kind'] === self::KIND_MONEDA ? 'moneda' : 'billete';
        $plural = $diferencia['kind'] === self::KIND_MONEDA ? 'monedas' : 'billetes';

        $pieza = $cuantas === 1
            ? '1 '.$singular.' de '.$valor
            : $cuantas.' '.$plural.' de '.$valor;

        return $diferencia['delta'] > 0
            ? 'Sobran '.$pieza.'.'
            : 'Faltan '.$pieza.'.';
    }

    /**
     * La DENOMINACION del catalogo con un id.
     *
     * @param  list<array{id: int, currency_id: int, value: string, kind: string}>  $catalogo
     * @return array{id: int, currency_id: int, value: string, kind: string}|null
     */
    private function busca(array $catalogo, int $id): ?array
    {
        foreach ($catalogo as $denominacion) {
            if ($denominacion['id'] === $id) {
                return $denominacion;
            }
        }

        return null;
    }
}
