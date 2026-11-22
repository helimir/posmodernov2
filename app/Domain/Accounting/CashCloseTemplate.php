<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * El MODELO del Z: el cierre de caja como DOCUMENTO.
 *
 * REGLA que fija esta clase: *el Z se imprime con el contenido EXACTO del Z
 * sellado*. El modelo se construye desde la FILA del Z, no desde un recalculo:
 * si el papel se compusiera con las cifras del dia, una venta corregida
 * despues del cierre cambiaria el papel, y el papel diria una cosa y el Z otra.
 * Un Z es un HECHO, y un hecho se imprime como fue.
 *
 * EL MODELO es UNO, y alimenta a los DOS renderizadores: el HTML (el papel de
 * la impresora termica) y el ESC/POS (la impresora de la tienda). Es el mismo
 * puente que `ReceiptTemplate` es para el comprobante fiscal.
 *
 * Y EL CONTEO POR DENOMINACION tambien sale de la fila: las PIEZAS que el
 * cajero conto son las del Z sellado. El CATALOGO (el valor de cada pieza) es
 * DATO de la tienda, y se consulta para NOMBRAR las piezas: no se recalcula
 * nada, se lee lo que se sello y se le pone el nombre que le corresponde.
 */
final readonly class CashCloseTemplate
{
    /**
     * @param  list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>  $payments
     * @param  list<array{id: int, value: string, kind: string, count: int, amount: string}>  $denominations
     */
    public function __construct(
        public int $id,
        public int $storeId,
        public string $storeName,
        public string $businessDate,
        public string $currencyCode,
        public int $decimals,
        public string $openingBalance,
        public string $expectedBalance,
        public string $countedBalance,
        public string $difference,
        public string $subtotal,
        public string $taxTotal,
        public string $grandTotal,
        public int $salesCount,
        public ?string $firstDocumentNumber,
        public ?string $lastDocumentNumber,
        public ?string $firstControlNumber,
        public ?string $lastControlNumber,
        public array $payments,
        public array $denominations,
        public ?string $closeReason,
        public ?string $closedByName,
        public ?string $closedAt,
        public string $contentHash,
        public bool $verified,
    ) {}

    /**
     * El MODELO a partir de la FILA del Z sellado.
     *
     * REGLA que fija este metodo: *aqui no se recalcula nada*. Cada cifra sale
     * de la columna que se sello, y la unica consulta extra es la del
     * CATALOGO de denominaciones (para nombrar las piezas) y la del nombre de
     * quien cerro.
     *
     * @param  object  $fila  La fila de `cash_closes`.
     */
    public static function fromClose(
        Connection $connection,
        object $fila,
        string $storeName,
        string $currencyCode,
        int $decimals,
    ): self {
        return new self(
            id: (int) $fila->id,
            storeId: (int) $fila->store_id,
            storeName: $storeName,
            businessDate: (string) $fila->business_date,
            currencyCode: $currencyCode,
            decimals: $decimals,
            openingBalance: PosAmount::text($fila->opening_balance),
            expectedBalance: PosAmount::text($fila->expected_balance),
            countedBalance: PosAmount::text($fila->counted_balance),
            difference: PosAmount::text($fila->difference),
            subtotal: PosAmount::text($fila->subtotal),
            taxTotal: PosAmount::text($fila->tax_total),
            grandTotal: PosAmount::text($fila->grand_total),
            salesCount: (int) $fila->sales_count,
            firstDocumentNumber: self::texto($fila->first_document_number ?? null),
            lastDocumentNumber: self::texto($fila->last_document_number ?? null),
            firstControlNumber: self::texto($fila->first_control_number ?? null),
            lastControlNumber: self::texto($fila->last_control_number ?? null),
            payments: self::pagos($fila->payments ?? null),
            denominations: self::denominaciones($connection, (int) $fila->store_id, $fila->counted_pieces ?? null),
            closeReason: self::texto($fila->close_reason ?? null),
            closedByName: self::nombreDe($connection, $fila->closed_by ?? null),
            closedAt: self::instante($fila->closed_at ?? null),
            contentHash: (string) $fila->content_hash,
            // LA FIRMA: si el hash no cuadra, alguien altero un Z sellado, y
            // eso se dice antes de imprimirlo.
            verified: CashClose::verify($fila),
        );
    }

    /**
     * Un IMPORTE como lo ENSENA el Z: a la escala de la moneda de la tienda.
     *
     * La escala es DATO de la moneda (no del codigo): una tienda con dos
     * decimales enseña dos, y una con ninguno enseña ninguno.
     */
    public function monto(string $importe): string
    {
        return (string) BigDecimal::of($importe)->toScale($this->decimals, RoundingMode::HALF_UP);
    }

    /**
     * El DESCUADRE con su SIGNO: un descuadre de mas y uno de menos no se
     * leen igual, y en papel el signo es lo que dice que fue.
     *
     * El signo del PAPER es una convencion de lectura (el del Z es el que
     * tiene el numero), y no cambia la cifra: un descuadre de mas es un
     * descuadre de mas.
     */
    public function descuadre(): string
    {
        $monto = $this->monto($this->difference);

        if (BigDecimal::of($this->difference)->isZero()) {
            return $monto;
        }

        return str_starts_with($monto, '-') ? $monto : '+'.$monto;
    }

    /**
     * El RANGO de un correlativo: el primero y el ultimo.
     *
     * REGLA que fija este metodo: *si el rango tiene un solo numero, se dice
     * una vez*. Un dia con una sola venta no imprime "0001 - 0001".
     */
    public function rango(?string $primero, ?string $ultimo): string
    {
        if ($primero === null && $ultimo === null) {
            return '-';
        }

        if ($primero === null) {
            return (string) $ultimo;
        }

        if ($ultimo === null || $ultimo === $primero) {
            return $primero;
        }

        return $primero.' - '.$ultimo;
    }

    /**
     * Los PAGOS del Z, ya en memoria.
     *
     * @return list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>
     */
    private static function pagos(mixed $crudos): array
    {
        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true);
        }

        if (! is_array($crudos)) {
            return [];
        }

        $pagos = [];

        foreach ($crudos as $pago) {
            if (! is_array($pago)) {
                continue;
            }

            $pagos[] = [
                'id' => isset($pago['id']) && $pago['id'] !== null ? (int) $pago['id'] : null,
                'name' => self::texto($pago['name'] ?? null),
                'type' => (string) ($pago['type'] ?? 'cash'),
                'amount' => PosAmount::text($pago['amount'] ?? '0'),
                'is_cash' => (bool) ($pago['is_cash'] ?? false),
            ];
        }

        return $pagos;
    }

    /**
     * El CONTEO por DENOMINACION: las piezas que el cajero conto, con el
     * nombre (el valor) de cada pieza y el dinero que representan.
     *
     * REGLA que fija este metodo: *el CONTEO es el del Z sellado*. El catalogo
     * de la tienda solo aporta el VALOR de cada pieza: sin ese valor, un
     * conteo de piezas no dice nada. Y las piezas que el cajero conto se
     * ordenan de MAYOR a MENOR, que es como cuenta un cajero.
     *
     * @return list<array{id: int, value: string, kind: string, count: int, amount: string}>
     */
    private static function denominaciones(Connection $connection, int $storeId, mixed $piezas): array
    {
        if (is_string($piezas)) {
            $piezas = json_decode($piezas, true);
        }

        if (! is_array($piezas) || $piezas === []) {
            return [];
        }

        $conteo = [];

        foreach ($piezas as $clave => $valor) {
            if (is_array($valor)) {
                $conteo[(int) ($valor['id'] ?? 0)] = (int) ($valor['count'] ?? 0);
            } else {
                $conteo[(int) $clave] = (int) $valor;
            }
        }

        $catalogo = (new CashDenominations($connection))->catalog($storeId);

        $denominaciones = [];

        foreach ($conteo as $id => $cuantas) {
            foreach ($catalogo as $denominacion) {
                if ($denominacion['id'] !== $id) {
                    continue;
                }

                $denominaciones[] = [
                    'id' => $id,
                    'value' => $denominacion['value'],
                    'kind' => $denominacion['kind'],
                    'count' => $cuantas,
                    'amount' => (string) PosAmount::decimal($denominacion['value'])
                        ->multipliedBy($cuantas)
                        ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP),
                ];
            }
        }

        usort($denominaciones, static function (array $a, array $b): int {
            $comparacion = PosAmount::decimal($b['value'])->compareTo(PosAmount::decimal($a['value']));

            return $comparacion !== 0 ? $comparacion : $a['id'] <=> $b['id'];
        });

        return $denominaciones;
    }

    /**
     * El NOMBRE de quien cerro: si no se sabe, el hueco se ve.
     */
    private static function nombreDe(Connection $connection, mixed $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $nombre = $connection->table('users')->where('id', (int) $userId)->value('name');

        return $nombre === null ? null : (string) $nombre;
    }

    /**
     * El INSTANTE del cierre, como lo enseña el papel.
     */
    private static function instante(mixed $closedAt): ?string
    {
        if ($closedAt === null) {
            return null;
        }

        return (string) Carbon::parse((string) $closedAt)->format('d/m/Y H:i');
    }

    /**
     * Un TEXTO o `null`.
     */
    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
