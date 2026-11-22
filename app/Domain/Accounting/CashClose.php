<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;

/**
 * El Z SELLADO: el cierre de caja como HECHO.
 *
 * REGLA que fija esta clase: *un cierre es un hecho, y un hecho se escribe
 * una vez*. Antes, el descuadre se calculaba en vivo, asi que corregir una
 * venta de ayer cambiaba el "cierre" de ayer: eso no es un cierre, es un
 * calculo. Aqui el Z es una FILA de `cash_closes`, con su contenido y con la
 * FIRMA de ese contenido.
 *
 * EL HISTORIAL es el motivo de la tabla: una fila por CIERRE, no una por dia.
 * Un dia que se reabre NO pierde su Z: la fila queda marcada (`reopened_at`,
 * `reopened_by`, `reopen_reason`) y, al volver a cerrar, se escribe OTRA fila
 * que es la vigente. La VIGENTE es la ultima fila NO reabierta de esa tienda
 * y esa fecha.
 *
 * LA FIRMA es el HASH del contenido: `sha256` de la forma CANONICA del Z. Se
 * comprueba AL LEER: si el hash no cuadra, alguien altero un Z sellado, y eso
 * se dice. Por eso la forma canonica se reconstruye SIEMPRE desde la fila: si
 * el hash se calculara sobre lo que se acaba de escribir, no detectaria nada.
 *
 * Y LOS IMPORTES viajan como TEXTO decimal: un flotante no es un dinero.
 */
final readonly class CashClose
{
    public function __construct(private Connection $connection) {}

    /**
     * SELLA el Z de una tienda y una fecha de negocio.
     *
     * REGLA que fija este metodo: *el Z se sella dentro de la transaccion del
     * cierre*. Si el snapshot falla, el cierre falla: no hay un dia cerrado
     * sin Z.
     *
     * Y EL Z GUARDA POR QUE se cerro asi (`$motivo`) y COMO se conto
     * (`$piezas`): un descuadre por encima del umbral no se cierra sin
     * explicacion, y una explicacion que no queda con el cierre no explica
     * nada.
     *
     * @param  array<string, mixed>  $contenido  El contenido del Z: las
     *                                           cifras, los correlativos y
     *                                           el desglose por medio de
     *                                           pago.
     * @param  int|null  $actorId  Quien cerro.
     * @param  string|null  $motivo  Por que se cerro asi. `null` cuando el
     *                               descuadre no lo exigia.
     * @param  array<int, int>|null  $piezas  Las piezas que el cajero conto,
     *                                        por id de denominacion. `null`
     *                                        cuando el cierre fue por importe.
     * @return int El id de la fila del Z.
     */
    public function seal(
        int $storeId,
        string $businessDate,
        int $registerId,
        array $contenido,
        ?int $actorId = null,
        ?string $motivo = null,
        ?array $piezas = null,
    ): int {
        $razon = $motivo === null ? null : trim($motivo);
        $razon = $razon === '' ? null : $razon;

        $contenido['close_reason'] = $razon;
        $contenido['counted_pieces'] = $piezas === null ? null : $piezas;

        $canonico = self::canonico($contenido);

        return (int) $this->connection->table('cash_closes')->insertGetId([
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'cash_register_id' => $registerId,
            'opening_balance' => $canonico['opening_balance'],
            'expected_balance' => $canonico['expected_balance'],
            'counted_balance' => $canonico['counted_balance'],
            'difference' => $canonico['difference'],
            // EL MOTIVO: por que se cerro asi.
            'close_reason' => $razon,
            // El CONTEO: las piezas que el cajero conto.
            'counted_pieces' => $piezas === null
                ? null
                : json_encode(
                    self::piezasCanonicas($piezas),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            'subtotal' => $canonico['subtotal'],
            'tax_total' => $canonico['tax_total'],
            'grand_total' => $canonico['grand_total'],
            'sales_count' => $canonico['sales_count'],
            'first_document_number' => $canonico['first_document_number'],
            'last_document_number' => $canonico['last_document_number'],
            'first_control_number' => $canonico['first_control_number'],
            'last_control_number' => $canonico['last_control_number'],
            // El desglose es una lista VARIABLE de filas: por eso es un json.
            'payments' => json_encode(
                $canonico['payments'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'content_hash' => self::hash($canonico),
            'closed_by' => $actorId,
            'closed_at' => now()->format('Y-m-d H:i:sP'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El Z VIGENTE de una tienda y una fecha: la ultima fila NO reabierta.
     *
     * `null` cuando no hay Z: la caja esta abierta, y entonces no hay nada
     * sellado que leer.
     */
    public function current(int $storeId, string $businessDate): ?object
    {
        return $this->connection->table('cash_closes')
            ->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->whereNull('reopened_at')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * TODO el HISTORIAL de Z de una tienda y una fecha, del mas antiguo al
     * mas reciente.
     *
     * @return list<object>
     */
    public function history(int $storeId, string $businessDate): array
    {
        return $this->connection->table('cash_closes')
            ->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * MARCA un Z como REABIERTO, con su motivo y con quien.
     *
     * La fila NO se borra: se queda, marcada. Un Z reabierta es historial, y
     * el historial es lo que deja ver que el dia se toco.
     */
    public function markReopened(int $closeId, string $motivo, ?int $actorId = null): void
    {
        $this->connection->table('cash_closes')
            ->where('id', $closeId)
            ->update([
                'reopened_at' => now()->format('Y-m-d H:i:sP'),
                'reopened_by' => $actorId,
                'reopen_reason' => $motivo,
                'updated_at' => now(),
            ]);
    }

    /**
     * La FIRMA del contenido: `sha256` de la forma canonica.
     *
     * @param  array<string, mixed>  $canonico
     */
    public static function hash(array $canonico): string
    {
        return hash('sha256', (string) json_encode(
            $canonico,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * Si el Z SIGUE SELLADO: la firma del contenido coincide con la guardada.
     *
     * REGLA que fija este metodo: *el hash se verifica al LEER*. Un Z
     * alterado no es un Z: es una fila que alguien toco, y hay que decirlo.
     */
    public static function verify(object $fila): bool
    {
        $contenido = [
            'business_date' => $fila->business_date,
            'opening_balance' => $fila->opening_balance,
            'expected_balance' => $fila->expected_balance,
            'counted_balance' => $fila->counted_balance,
            'difference' => $fila->difference,
            'subtotal' => $fila->subtotal,
            'tax_total' => $fila->tax_total,
            'grand_total' => $fila->grand_total,
            'sales_count' => $fila->sales_count,
            'first_document_number' => $fila->first_document_number,
            'last_document_number' => $fila->last_document_number,
            'first_control_number' => $fila->first_control_number,
            'last_control_number' => $fila->last_control_number,
            'payments' => self::pagosDeLaFila($fila),
        ];

        // EL MOTIVO y el CONTEO son OPCIONALES en la forma canonica: un Z
        // sellado antes de que existieran no los tiene, y su firma se
        // calculo sin ellos. Por eso solo se anaden cuando los hay: un Z
        // viejo sigue cuadrando, y uno nuevo los cubre.
        $motivo = $fila->close_reason ?? null;

        if ($motivo !== null) {
            $contenido['close_reason'] = $motivo;
        }

        $piezas = $fila->counted_pieces ?? null;

        if ($piezas !== null) {
            $contenido['counted_pieces'] = $piezas;
        }

        $canonico = self::canonico($contenido);

        return hash_equals((string) $fila->content_hash, self::hash($canonico));
    }

    /**
     * La FORMA CANONICA del contenido del Z.
     *
     * REGLA que fija este metodo: *el hash se calcula sobre una forma
     * canonica, no sobre lo que se escribe*. Los importes se normalizan a
     * cuatro decimales, los numeros de documento son texto o `null`, y el
     * desglose se ordena por medio de pago: dos contenidos iguales tienen el
     * mismo hash, aunque se hayan escrito en otro orden.
     *
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public static function canonico(array $contenido): array
    {
        $canonico = [
            'business_date' => (string) ($contenido['business_date'] ?? ''),
            'opening_balance' => PosAmount::text($contenido['opening_balance'] ?? '0'),
            'expected_balance' => PosAmount::text($contenido['expected_balance'] ?? '0'),
            'counted_balance' => PosAmount::text($contenido['counted_balance'] ?? '0'),
            'difference' => PosAmount::text($contenido['difference'] ?? '0'),
            'subtotal' => PosAmount::text($contenido['subtotal'] ?? '0'),
            'tax_total' => PosAmount::text($contenido['tax_total'] ?? '0'),
            'grand_total' => PosAmount::text($contenido['grand_total'] ?? '0'),
            'sales_count' => (int) ($contenido['sales_count'] ?? 0),
            'first_document_number' => self::texto($contenido['first_document_number'] ?? null),
            'last_document_number' => self::texto($contenido['last_document_number'] ?? null),
            'first_control_number' => self::texto($contenido['first_control_number'] ?? null),
            'last_control_number' => self::texto($contenido['last_control_number'] ?? null),
            'payments' => self::pagosCanonicos($contenido['payments'] ?? []),
        ];

        // EL MOTIVO y el CONTEO son OPCIONALES: solo entran en la forma
        // canonica cuando los hay. Un Z sellado antes de que existieran se
        // firmo sin ellos, y su firma sigue valiendo.
        if (array_key_exists('close_reason', $contenido) && $contenido['close_reason'] !== null) {
            $canonico['close_reason'] = self::texto($contenido['close_reason']);
        }

        if (array_key_exists('counted_pieces', $contenido) && $contenido['counted_pieces'] !== null) {
            $canonico['counted_pieces'] = self::piezasCanonicas($contenido['counted_pieces']);
        }

        return $canonico;
    }

    /**
     * Las PIEZAS contadas, en forma canonica: una lista ordenada por
     * denominacion.
     *
     * REGLA que fija este metodo: *el conteo es una lista ORDENADA*. Viene
     * como mapa (id => cantidad) porque asi lo cuenta el cajero, pero la
     * firma se calcula sobre la lista ordenada: dos conteos iguales tienen la
     * misma firma, escriban como los escriban.
     *
     * @return list<array{id: int, count: int}>
     */
    private static function piezasCanonicas(mixed $piezas): array
    {
        if (is_string($piezas)) {
            $piezas = json_decode($piezas, true);
        }

        if (! is_array($piezas)) {
            return [];
        }

        $canonicas = [];

        foreach ($piezas as $clave => $valor) {
            if (is_array($valor)) {
                $id = (int) ($valor['id'] ?? 0);
                $cuantas = (int) ($valor['count'] ?? 0);
            } else {
                $id = (int) $clave;
                $cuantas = (int) $valor;
            }

            $canonicas[] = ['id' => $id, 'count' => $cuantas];
        }

        usort($canonicas, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });

        return $canonicas;
    }

    /**
     * Los pagos de una fila del Z, ya en memoria.
     *
     * @return list<array<string, mixed>>
     */
    private static function pagosDeLaFila(object $fila): array
    {
        $crudos = $fila->payments ?? null;

        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true);
        }

        return is_array($crudos) ? $crudos : [];
    }

    /**
     * El DESGLOSE canonico: una lista ordenada por medio de pago.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     * @return list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>
     */
    private static function pagosCanonicos(array $pagos): array
    {
        $canonicos = [];

        foreach (array_values($pagos) as $pago) {
            if (! is_array($pago)) {
                continue;
            }

            $canonicos[] = [
                'id' => isset($pago['id']) && $pago['id'] !== null ? (int) $pago['id'] : null,
                'name' => self::texto($pago['name'] ?? null),
                'type' => (string) ($pago['type'] ?? 'cash'),
                'amount' => PosAmount::text($pago['amount'] ?? '0'),
                'is_cash' => (bool) ($pago['is_cash'] ?? false),
            ];
        }

        // El ORDEN es el del medio de pago: dos contenidos iguales tienen el
        // mismo hash aunque se hayan escrito en otro orden.
        usort($canonicos, static function (array $a, array $b): int {
            $ida = $a['id'] ?? -1;
            $idb = $b['id'] ?? -1;

            return $ida <=> $idb;
        });

        return $canonicos;
    }

    /**
     * Un TEXTO o `null`: un numero de documento que no esta es `null`, no una
     * cadena vacia que se confunde con un documento que si existe.
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
