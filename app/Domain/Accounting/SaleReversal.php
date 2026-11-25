<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * La REVERSA de una venta anulada: el espejo de su asiento, con el efectivo
 * hacia el PASIVO.
 *
 * REGLA que fija esta clase: *la reversa de una venta es el ESPEJO de su
 * asiento, y el efectivo NO se revierte*. El espejo es exacto aunque despues
 * el plan de cuentas cambie, y por eso no recalcula nada: lee lo que se
 * escribio.
 *
 * Y EL EFECTIVO SE QUITA DEL ESPEJO, Y EN SU LUGAR ENTRA EL CREDITO A FAVOR
 * DEL CLIENTE:
 *
 *  - el dinero de la venta entro en el cajon y SIGUE AHI: una venta anulada
 *    sigue siendo caja, y el Z del dia no cambia. Por eso la reversa NO
 *    acredita la cuenta de efectivo: si lo hiciera, el saldo en curso de la
 *    caja del dia bajaria y el dia cerraria con un descuadre que nadie
 *    explico;
 *  - lo que la venta DEJABA es una DEUDA: con cliente, la parte que no se
 *    cobro (y el libro del cliente la rebaja con el reintegro de la nota de
 *    credito), y sin cliente, la nota de credito entera. Esa deuda es un
 *    PASIVO de la tienda, y por eso se Asienta en la cuenta de pasivo: es el
 *    CREDITO A FAVOR del cliente.
 *
 * Y ASI EL LIBRO DEL CLIENTE Y EL CONTABLE DICEN LO MISMO: el reintegro de la
 * nota de credito deja al cliente con un credito a favor de lo que pago, y el
 * pasivo de la tienda tiene EXACTAMENTE ese importe. Antes de este asiento, el
 * cliente tinha un credito a favor que el libro contable no conocia, y los dos
 * libros decian cosas distintas.
 *
 * Y LA REVERSA NECESITA LA CUENTA DE PASIVO SOLO CUANDO EL CLIENTE ENTREGO
 * DINERO: la reversa devuelve lo que el cliente entrego, y si no entrego
 * nada, no hay pasivo que devolver. Una venta a CREDITO tiene su "debe" en la
 * cuenta `receivable` (que es obligatoria), y su reversa la cancela sin tocar
 * el pasivo. Y el pasivo es un rol OBLIGATORIO del plan completo: cuando la
 * reversa lo necesita y la tienda no lo tiene, la anulacion FALLA con un
 * 422 que dice que cuenta falta y que hay que hacer. Una anulacion a medias
 * (sin su reversa) es una venta que nadie sabe lo que es, y el cajero se
 * queda bloqueado con un error que nadie puede corregir: eso es PEOR que no
 * poder cobrar.
 */
final readonly class SaleReversal
{
    public function __construct(private Connection $connection) {}

    /**
     * Escribe la REVERSA del asiento de una venta anulada.
     *
     * @param  int  $saleId  La venta anulada.
     * @param  int  $storeId  La tienda de la venta.
     * @param  DateTimeImmutable  $businessDate  La FECHA DE NEGOCIO de la
     *                                           venta: la reversa cae en el
     *                                           dia que la venta cayo.
     * @param  string|null  $invoiceNo  El comprobante de la venta, para la
     *                                  descripcion del asiento.
     * @param  int|null  $actorId  Quien anulo la venta.
     * @return int|null El id del asiento de la reversa, o `null` si la venta
     *                  NO tiene asiento que espejar.
     *
     * @throws RuntimeException Cuando la reversa necesita la cuenta de pasivo
     *                          y la tienda no la tiene.
     */
    public function write(
        int $saleId,
        int $storeId,
        DateTimeImmutable $businessDate,
        ?string $invoiceNo = null,
        ?int $actorId = null,
    ): ?int {
        // El ASIENTO ORIGINAL de la venta: la reversa es su espejo.
        $lineas = $this->lineasDelAsiento($saleId, $storeId);

        if ($lineas->isEmpty()) {
            return null;
        }

        $efectivo = $this->cuentaDeRol($storeId, AccountRole::Cash);
        $pasivo = $this->cuentaDeRol($storeId, AccountRole::Liability);

        // EL INVIARIANTE: si el espejo sustituye el efectivo por el pasivo,
        // el pasivo TIENE que existir. Y esto se comprueba ANTES de
        // escribir: una reversa a medias es una venta que nadie sabe lo que
        // es.
        $this->exigeElPasivoDelEspejo($lineas, $efectivo, $pasivo, $storeId);

        $espejo = [];

        foreach ($lineas as $linea) {
            $debe = (string) $linea->debit;
            $haber = (string) $linea->credit;

            // EL EFECTIVO NO SE REVIERTE: el dinero sigue en el cajon. En su
            // lugar, el CREDITO A FAVOR del cliente, que es un pasivo.
            if ($efectivo !== '' && (string) $linea->account_code === $efectivo) {
                $espejo[] = [
                    'account' => $pasivo,
                    'debit' => '0',
                    'credit' => $debe,
                ];

                continue;
            }

            $espejo[] = [
                'account' => (string) $linea->account_code,
                'debit' => $haber,
                'credit' => $debe,
            ];
        }

        return (new LedgerWriter($this->connection))->write(
            entryType: 'refund',
            storeId: $storeId,
            occurredAt: new DateTimeImmutable('now'),
            businessDate: $businessDate,
            lines: $espejo,
            // El enlace es el de la VENTA, con su propio tipo: el enlace dice
            // QUE hecho origina el movimiento, y este es la REVERSA de una
            // venta, no la venta.
            referenceType: 'sale_void',
            referenceId: $saleId,
            actorId: $actorId,
            description: 'Anulacion de la venta #'.($invoiceNo ?? $saleId),
        );
    }

    /**
     * ¿La REVERSA de esta venta necesita la cuenta de PASIVO?
     *
     * REGLA que fija este metodo: *la reversa necesita el pasivo SOLO cuando
     * el cliente entrego dinero*. La reversa devuelve lo que el cliente
     * entrego: si no entrego nada, no hay pasivo que devolver, y el "debe" de
     * una venta a credito esta en la cuenta `receivable`, que es obligatoria.
     *
     * Y COMO SE SABE QUE ENTREGO DINERO: el espejo sustituye la linea del
     * EFECTIVO por la del pasivo, y el efectivo solo aparece en el asiento de
     * la venta cuando el cliente entrego algo.
     */
    public function necesitaPasivo(int $saleId, int $storeId): bool
    {
        $lineas = $this->lineasDelAsiento($saleId, $storeId);

        if ($lineas->isEmpty()) {
            return false;
        }

        return $this->sustituyeElEfectivo($lineas, $this->cuentaDeRol($storeId, AccountRole::Cash));
    }

    /**
     * EXIGE la cuenta de PASIVO cuando la reversa la necesita.
     *
     * REGLA que fija este metodo: *el pasivo es obligatorio para la
     * anulacion de una venta COBRADA, y su ausencia es un FALLO con un
     * mensaje que dice que hacer*. El rol `liability` es obligatorio del plan
     * completo, y una tienda sin el no puede deshacer una venta cobrada por
     * error.
     *
     * @throws RuntimeException Cuando la reversa necesita el pasivo y la
     *                          tienda no lo tiene.
     */
    public function exigePasivo(int $saleId, int $storeId): void
    {
        $lineas = $this->lineasDelAsiento($saleId, $storeId);

        $efectivo = $this->cuentaDeRol($storeId, AccountRole::Cash);
        $pasivo = $this->cuentaDeRol($storeId, AccountRole::Liability);

        $this->exigeElPasivoDelEspejo($lineas, $efectivo, $pasivo, $storeId);
    }

    /**
     * EXIGE el pasivo cuando el ESPEJO lo necesita.
     *
     * Es la regla en UN solo sitio: el espejo sustituye el efectivo por el
     * pasivo, y si lo sustituye, el pasivo tiene que existir.
     *
     * @param  Collection<int, object>  $lineas
     *
     * @throws RuntimeException Cuando el espejo necesita el pasivo y la
     *                          tienda no lo tiene.
     */
    private function exigeElPasivoDelEspejo(Collection $lineas, string $efectivo, string $pasivo, int $storeId): void
    {
        if (! $this->sustituyeElEfectivo($lineas, $efectivo)) {
            return;
        }

        if ($pasivo !== '') {
            return;
        }

        throw new RuntimeException($this->mensajeSinPasivo($storeId));
    }

    /**
     * ¿La tienda tiene cuenta de PASIVO?
     *
     * Es la pregunta que decide si la reversa se puede escribir, y por eso es
     * PUBLICA: la pantalla y la auditoria tienen que poder decir si los dos
     * libros del cliente y el contable coinciden.
     */
    public function tienePasivo(int $storeId): bool
    {
        return $this->cuentaDeRol($storeId, AccountRole::Liability) !== '';
    }

    /**
     * El MENSAJE de la anulacion que no se puede escribir: que cuenta falta y
     * que hay que hacer.
     *
     * El texto es de PANTALLA, y por eso esta en ingles: lo que el dominio
     * decide es QUE falta, y COMO se dice es de la costura. Y dice la via:
     * el rol se asigna a una cuenta EXISTENTE de tipo pasivo desde el plan de
     * cuentas.
     */
    private function mensajeSinPasivo(int $storeId): string
    {
        return "The store {$storeId} has no liability account and this sale cannot be voided: the reversal needs it, "
            .'because the customer delivered money and the store owes it back as a credit. '
            ."Assign the role 'liability' to an account of type liability in the chart of accounts and try again.";
    }

    /**
     * Las LINEAS del asiento de la venta: el espejo se lee de lo que se
     * escribio.
     *
     * @return Collection<int, object>
     */
    private function lineasDelAsiento(int $saleId, int $storeId): Collection
    {
        $asiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first(['id']);

        if ($asiento === null) {
            return collect();
        }

        return $this->connection->table('ledger_lines')
            ->where('ledger_entry_id', (int) $asiento->id)
            ->orderBy('id')
            ->get(['account_code', 'debit', 'credit']);
    }

    /**
     * ¿El espejo SUSTITUYE el efectivo por el pasivo?
     *
     * Es lo que decide si la reversa necesita la cuenta de pasivo: si el
     * asiento de la venta no tiene linea de efectivo, el cliente no entrego
     * dinero y no hay pasivo que devolver.
     *
     * @param  Collection<int, object>  $lineas
     */
    private function sustituyeElEfectivo(Collection $lineas, string $efectivo): bool
    {
        if ($efectivo === '') {
            return false;
        }

        foreach ($lineas as $linea) {
            if ((string) $linea->account_code === $efectivo) {
                return true;
            }
        }

        return false;
    }

    /**
     * La CUENTA que cumple un rol en la tienda, o `''` si no la cumple.
     */
    private function cuentaDeRol(int $storeId, AccountRole $rol): string
    {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        return trim((string) ($roles[$rol->value] ?? ''));
    }
}
