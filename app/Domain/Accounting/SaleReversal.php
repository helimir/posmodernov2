<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
use DateTimeImmutable;
use Illuminate\Database\Connection;

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
 * Y LA REVERSA NECESITA LA CUENTA DE PASIVO, que es un ROL DEL PLAN (y el
 * rol es OPCIONAL): una tienda que no lo tiene NO escribe la reversa, y su
 * libro del cliente y su libro contable siguen difiriendo. Por eso este
 * metodo devuelve `null` en ese caso, en vez de fallar: la anulacion de la
 * venta es un hecho fiscal que no depende del plan de cuentas.
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
     * @return int|null El id del asiento de la reversa, o `null` si la tienda
     *                  no tiene cuenta de pasivo (y entonces los dos libros
     *                  siguen difiriendo).
     */
    public function write(
        int $saleId,
        int $storeId,
        DateTimeImmutable $businessDate,
        ?string $invoiceNo = null,
        ?int $actorId = null,
    ): ?int {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        // La cuenta de PASIVO: donde vive el credito a favor del cliente. Sin
        // ella no hay donde asentar la deuda, y la anulacion sigue siendo
        // valida: es un hecho fiscal, no una cuenta.
        $pasivo = trim((string) ($roles[AccountRole::Liability->value] ?? ''));

        if ($pasivo === '') {
            return null;
        }

        $efectivo = trim((string) ($roles[AccountRole::Cash->value] ?? ''));

        // El ASIENTO ORIGINAL de la venta: la reversa es su espejo.
        $asiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first(['id']);

        if ($asiento === null) {
            return null;
        }

        $lineas = $this->connection->table('ledger_lines')
            ->where('ledger_entry_id', (int) $asiento->id)
            ->orderBy('id')
            ->get(['account_code', 'debit', 'credit']);

        if ($lineas->isEmpty()) {
            return null;
        }

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
     * ¿La tienda tiene cuenta de PASIVO?
     *
     * Es la pregunta que decide si la reversa se escribe, y por eso es
     * PUBLICA: la pantalla y la auditoria tienen que poder decir si los dos
     * libros del cliente y el contable coinciden.
     */
    public function tienePasivo(int $storeId): bool
    {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        return trim((string) ($roles[AccountRole::Liability->value] ?? '')) !== '';
    }
}
