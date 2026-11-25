<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\SaleReversal;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\DerivedDocumentService;
use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Fiscal\FiscalProviderScope;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Sales\SaleVoidRequests;
use App\Support\Audit\AuditWriter;
use App\Support\Fiscal\StoreAccess;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * La ANULACION de una venta: la via por la que una venta deja de estar en
 * pie.
 *
 * REGLA que fija esta clase: *una venta con comprobante EMITIDO no se
 * "desemite": ante la autoridad eso no existe*. Un comprobante publicado no
 * se retira, y su numero no vuelve al correlativo. La via es la NOTA DE
 * CREDITO: un documento fiscal DERIVADO, con su propia numeracion en la
 * MISMA serie, que referencia el original (numero, fecha, control y monto).
 *
 * Y LA ANULACION ES UNA UNIDAD ATOMICA: o se anula la venta, se anula su
 * comprobante, se emite su nota y se compensa el libro del cliente, o no se
 * escribe NADA. Una venta anulada a medias (sin su nota, o con su nota y sin
 * su estado) es una venta que nadie sabe lo que es.
 *
 * Y LA ANULACION ES FISCAL Y CONTABLE, NO FISICA: la mercaderia NO vuelve al
 * almacen. La devolucion con la reposicion del lote EXACTO es otra unidad, y
 * un stock repuesto aqui seria mercaderia que nadie ha devuelto. Por eso la
 * pantalla lo dice.
 *
 * Y LA CAJA DEL DIA NO SE TOCA: el dinero entro en el cajon y sigue ahi. Una
 * venta anulada sigue siendo caja, y el Z del dia no cambia. Devolver el
 * dinero es otra operacion.
 *
 * Y UNA VENTA DE UN DIA CERRADO NO SE ANULA SIN REABRIR ESE DIA: el Z
 * sellado es el cierre HECHO, con las cifras de ese dia, y corregir la venta
 * sin reabrirlo dejaria un Z que ya no describe el dia. Por eso la
 * anulacion falla con un 422 que dice que hay que reabrir, y el que la
 * ejecuta tiene el permiso de reabrir (`cash_day.reopen`), con su motivo.
 *
 * Y EL MOTIVO DE LA ANULACION es el de la SOLICITUD si la venta tiene una
 * pendiente (lo escribio quien pidio que le anulen), y el del cuerpo si no
 * la tiene. Quien pide explica por que, y esa explicacion es la que queda
 * escrita en la nota de credito y en la auditoria.
 */
final class SaleVoidController extends Controller
{
    /**
     * El TIPO del documento DERIVADO que deja sin efecto a la venta.
     */
    private const NOTA = 'credit_note';

    /**
     * Los DOCUMENTOS DERIVADOS: se construyen aqui, con la MISMA conexion
     * del controlador, y no se inyectan: el contenedor no puede resolverlos
     * solos (el `NumberSeries` depende de `ConnectionInterface`, que no esta
     * registrado), y la transaccion de la anulacion tiene que ser la misma
     * que la de los documentos que escribe.
     */
    private readonly DerivedDocumentService $derived;

    public function __construct(
        private readonly Connection $connection,
        private readonly Container $container,
        private readonly CustomerBalanceService $balances,
        private readonly StoreAccess $access,
        private readonly CashRegisterService $registers,
        private readonly SaleVoidRequests $requests,
    ) {
        $this->derived = new DerivedDocumentService(
            $connection,
            new NumberSeries($connection),
        );
    }

    /**
     * ANULA una venta, con su MOTIVO.
     *
     * Responde 200 con `{ sale_id, status, reason, voided_at, note }`: el
     * estado de la venta y su nota de credito (`null` si la venta no tenia
     * comprobante emitido, porque entonces no hay nada que anular ante la
     * autoridad).
     *
     * Y UN FALLO NO PIERDE LA VENTA: si la nota no se puede emitir, la
     * operacion se revierte entera y la venta sigue como estaba. El numero
     * quemado tampoco: la numeracion esta dentro de la misma unidad atomica.
     *
     * Y EL MOTIVO: el de la SOLICITUD si la venta tiene una pendiente, y el
     * del cuerpo si no la tiene. Quien pidio explico POR QUE, y esa
     * explicacion es la que lleva la anulacion.
     *
     * Y EL DIA DE NEGOCIO DE LA VENTA NO PUEDE ESTAR CERRADO: una venta de
     * un dia cerrado se corrige REABRIENDO ese dia, y el Z sellado se
     * queda en el historial.
     */
    public function anular(Request $request, int $sale): JsonResponse
    {
        $datos = $request->all();

        // 1. LA VENTA. Una venta que no existe, o de una tienda a la que el
        //    usuario no tiene acceso, es un 404: no se le dice que existe.
        $venta = $this->connection->table('sales')->where('id', $sale)->first();

        abort_if($venta === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $venta->store_id),
            404
        );

        // 2. UNA VENTA ANULADA NO SE ANULA DOS VECES: la anulacion es un
        //    HECHO, y un hecho repetido es una segunda nota de credito y un
        //    segundo reintegro en el libro del cliente.
        if ((string) $venta->status === 'cancelled') {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' ya esta anulada: no se anula dos veces.',
            ], 422);
        }

        // 3. EL MOTIVO es OBLIGATORIO: una venta anulada sin motivo es una
        //    venta que nadie sabe por que se toco.
        //
        //    Y EL MOTIVO DE LA SOLICITUD es EL DEL MOTIVO: cuando el cajero
        //    ha pedido la anulacion, la razon que explica la anulacion es la
        //    que escribio el. Quien ejecuta la anulacion no reescribe la voz
        //    de quien pidio.
        $solicitud = $this->solicitudDeAnulacion($venta);

        $motivoDelCuerpo = trim((string) ($datos['reason'] ?? ''));

        $motivoDeLaSolicitud = $solicitud === null
            ? ''
            : trim((string) $solicitud['reason']);

        $motivo = $motivoDeLaSolicitud !== '' ? $motivoDeLaSolicitud : $motivoDelCuerpo;

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Una venta se anula con un MOTIVO: '
                    .'una venta anulada sin motivo es una venta que nadie sabe por que se toco.',
            ], 422);
        }

        // 4. EL DIA DE NEGOCIO DE LA VENTA NO PUEDE ESTAR CERRADO. El Z
        //    sellado es el cierre HECHO, con las cifras de ese dia: anular
        //    una venta sin reabrirlo dejaria un Z que ya no describe el dia.
        //    Y reabrir un dia exige su motivo, y deja el Z anterior en el
        //    historial: por eso la anulacion falla y dice que hay que
        //    reabrir, en vez de reabrir por su cuenta.
        if ($this->diaCerrado($venta)) {
            return response()->json([
                'errorMsg' => 'El dia de negocio '.(string) $venta->sale_date.' de la tienda '
                    .(int) $venta->store_id.' esta CERRADO: una venta de un dia cerrado no se anula sin '
                    .'reabrirlo. Reabre el dia con su motivo (el Z sellado se queda en el historial) '
                    .'y vuelve a intentarlo.',
            ], 422);
        }

        $actorId = $request->user()?->id;

        try {
            $resultado = $this->connection->transaction(function () use ($venta, $sale, $motivo, $actorId): array {
                $documentoId = $venta->fiscal_document_id === null ? null : (int) $venta->fiscal_document_id;

                $documento = $documentoId === null
                    ? null
                    : $this->connection->table('fiscal_documents')->where('id', $documentoId)->first();

                // El ESTADO del comprobante, ANTES de tocarlo: la nota de
                // credito se emite sobre un comprobante EMITIDO, y anularlo
                // cambia su estado.
                $emitido = $documento !== null && (string) $documento->status === 'issued';

                $notaId = null;

                if ($emitido) {
                    if ($documento->series_id === null) {
                        throw new RuntimeException(
                            "El comprobante {$documento->id} no tiene serie: no se puede anular."
                        );
                    }

                    // 5. EL COMPROBANTE: se anula, y su numero queda
                    //    CONSUMIDO. Un numero publicado no vuelve al
                    //    correlativo, jams.
                    $this->anulaElComprobante(
                        (int) $documento->id,
                        (int) $documento->series_id,
                        $sale,
                        $motivo,
                        $actorId,
                    );

                    // 6. LA NOTA DE CREDITO: el documento DERIVADO que
                    //    lleva la correccion ante la autoridad, con su
                    //    PROPIA numeracion en la MISMA serie.
                    $notaId = $this->derived->issueNote(
                        (int) $documento->id,
                        self::NOTA,
                        $this->ahoraDeLaTienda((int) $venta->store_id),
                        $actorId,
                    );
                }

                // 7. LA VENTA queda anulada.
                $this->connection->table('sales')->where('id', $sale)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

                // 8. EL LIBRO DEL CLIENTE: la nota de credito RESTA el total
                //    de la venta, y el reintegro es de SU propio tipo.
                $this->compensaElCredito($venta, $notaId);

                // 8 bis. LA REVERSA CONTABLE: el espejo del asiento de la
                //      venta, con el efectivo hacia la cuenta de PASIVO (el
                //      credito a favor del cliente). El dinero sigue en el
                //      cajon, asi que la caja del dia NO se toca, y el pasivo
                //      de la tienda queda con la deuda que la nota de
                //      credito dejo: los dos libros dicen lo mismo.
                //
                //      Y SI LA TIENDA NO TIENE CUENTA DE PASIVO, la reversa
                //      NO se escribe y la anulacion sigue adelante: la
                //      anulacion de una venta es un HECHO FISCAL, y no
                //      depende del plan de cuentas. El hueco se dice en la
                //      respuesta y en la auditoria.
                $reversaId = (new SaleReversal($this->connection))->write(
                    (int) $venta->id,
                    (int) $venta->store_id,
                    new DateTimeImmutable((string) $venta->sale_date),
                    (string) ($venta->invoice_no ?? ''),
                    $actorId,
                );

                return [
                    'sale_id' => $sale,
                    'status' => 'cancelled',
                    'reason' => $motivo,
                    'voided_at' => $this->ahoraDeLaTienda((int) $venta->store_id)->format('Y-m-d H:i:s'),
                    'note' => $notaId === null ? null : $this->nota($notaId),
                    'reversal_entry_id' => $reversaId,
                    'reversal_written' => $reversaId !== null,
                ];
            });
        } catch (RuntimeException $fallo) {
            // Un fallo del dominio (la serie sin numeros, el cliente sin
            // ficha, el comprobante que no existe) es un fallo de la
            // OPERACION: la pantalla lo enseña y la venta sigue como estaba.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        // 9. LA AUDITORIA: quien anulo la venta y por que. Una venta anulada
        //    sin rastro es una venta que nadie puede defender despues.
        (new AuditWriter($this->connection))->recordFor(
            $request,
            'sale.voided',
            'sale',
            (int) $resultado['sale_id'],
            [
                'reason' => $motivo,
                'invoice_no' => (string) ($venta->invoice_no ?? ''),
                'credit_note_id' => $resultado['note']['id'] ?? null,
                'credit_note_number' => $resultado['note']['number'] ?? null,
                // Y SI LA ANULACION VIENE DE UNA SOLICITUD: quien la pidio y
                // cuando. Y el motivo que escribio quien ANULO, si escribio
                // otro que no es el de la solicitud: no se pierde, pero no
                // sustituye al del que pidio.
                'void_requested_by' => $solicitud['requested_by'] ?? null,
                'void_requested_at' => $solicitud['requested_at'] ?? null,
                'voided_by_reason' => $motivoDelCuerpo !== '' && $motivoDelCuerpo !== $motivo
                    ? $motivoDelCuerpo
                    : null,
                // Y LA REVERSA: si se escribio, el libro del cliente y el
                // contable dicen lo mismo. Si NO se escribio, es porque la
                // tienda no tiene cuenta de pasivo, y el hueco se ve aqui.
                'reversal_entry_id' => $resultado['reversal_entry_id'] ?? null,
                'reversal_written' => $resultado['reversal_written'] ?? false,
            ],
            (int) $venta->store_id
        );

        // Y EL AVISO: si la tienda no tiene cuenta de pasivo, la reversa NO
        // se escribio y el libro del cliente y el contable siguen diciendo
        // cosas distintas. El hueco se ve, no se esconde.
        $resultado['warning'] = ($resultado['reversal_written'] ?? false)
            ? null
            : 'La tienda no tiene cuenta de pasivo: la reversa no se escribio y el libro del cliente y el contable '
                .'siguen diciendo cosas distintas. Asigne el rol liability a una cuenta de tipo liability.';

        return response()->json($resultado);
    }

    /**
     * La SOLICITUD de anulacion PENDIENTE que tiene la venta, si la tiene.
     *
     * REGLA que fija este metodo: *el motivo de la anulacion es el de la
     * peticion PENDIENTE*. Solo de la pendiente: una peticion ya cerrada
     * (rechazada o cancelada) no explica la anulacion, y el motivo de la
     * anulacion es el del cuerpo si no hay ninguna pendiente.
     *
     * @return array{reason: string, requested_at: string|null, requested_by: int|null}|null
     */
    private function solicitudDeAnulacion(object $venta): ?array
    {
        $solicitud = $this->requests->pending((int) $venta->id);

        if ($solicitud === null) {
            return null;
        }

        return [
            'reason' => (string) $solicitud->reason,
            'requested_at' => $this->instanteDeLaFila($solicitud->requested_at),
            'requested_by' => $solicitud->requested_by === null
                ? null
                : (int) $solicitud->requested_by,
        ];
    }

    /**
     * ¿El dia de negocio de la VENTA esta cerrado?
     *
     * REGLA que fija este metodo: *el estado del dia es el de SU caja*, y lo
     * pregunta el dominio (`CashRegisterService`), que es el dueno de esa
     * tabla. La fecha es la de la VENTA (`sale_date`), no la de hoy: lo que
     * se corrige es el dia en el que se cobro.
     */
    private function diaCerrado(object $venta): bool
    {
        $fecha = (string) $venta->sale_date;

        if ($fecha === '') {
            return false;
        }

        return $this->registers->statusFor(
            (int) $venta->store_id,
            new DateTimeImmutable($fecha)
        ) === 'closed';
    }

    /**
     * Un INSTANTE de la base, en ISO 8601.
     */
    private function instanteDeLaFila(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return Carbon::parse((string) $valor)->toIso8601String();
    }

    /**
     * ANULA el comprobante fiscal de la venta.
     *
     * La anulacion es LOCAL: el actor intermedio no interviene, porque lo
     * que se anula es el efecto local del comprobante y la correccion la
     * lleva la nota de credito. Pero el SERVICIO del dominio se construye
     * con el canal, y el ambito es lo que dice de que serie es: se abre, se
     * hace la anulacion y se cierra.
     */
    private function anulaElComprobante(
        int $documentId,
        int $seriesId,
        int $saleId,
        string $motivo,
        ?int $actorId,
    ): void {
        $ambito = $this->container->make(FiscalProviderScope::class);

        try {
            $ambito->dentroDe($seriesId, $saleId);

            $servicio = new FiscalIssuanceService(
                $this->connection,
                $this->container->make(ElectronicInvoiceProvider::class),
            );

            $servicio->void($documentId, $motivo, $actorId);
        } finally {
            $ambito->limpiar();
        }
    }

    /**
     * La NOTA DE CREDITO de la anulacion: su numero y su fecha.
     *
     * El numero es el COMPLETO (el prefijo de la serie mas el secuencial),
     * como el de la venta: un secuencial suelto no identifica un documento
     * fiscal.
     *
     * @return array{id: int, number: string, issue_date: string|null, issued_at: string|null}
     */
    private function nota(int $notaId): array
    {
        $nota = $this->connection->table('fiscal_documents')->where('id', $notaId)->first();

        if ($nota === null) {
            throw new RuntimeException("La nota de credito {$notaId} no existe.");
        }

        $prefijo = null;

        if ($nota->series_id !== null) {
            $prefijo = $this->connection->table('fiscal_series')
                ->where('id', (int) $nota->series_id)
                ->value('prefix');
        }

        $numero = (string) $nota->number;

        $completo = $prefijo === null || trim((string) $prefijo) === ''
            ? $numero
            : trim((string) $prefijo).$numero;

        return [
            'id' => (int) $nota->id,
            'number' => $completo,
            'issue_date' => $nota->issue_date === null ? null : (string) $nota->issue_date,
            'issued_at' => $nota->issued_at === null ? null : (string) $nota->issued_at,
        ];
    }

    /**
     * COMPENSA el libro del cliente: la nota de credito RESTA el total de la
     * venta.
     *
     * El importe es el de la NOTA (que es el del original), y el reintegro
     * es de SU PROPIO tipo: un reintegro no es un cobro, y el libro lo
     * distingue. Y sin cliente no hay libro que compensar.
     */
    private function compensaElCredito(object $venta, ?int $notaId): void
    {
        if ($venta->customer_id === null) {
            return;
        }

        $importe = $notaId === null
            ? $this->totalDeLaVenta((int) $venta->id)
            : $this->totalDeLaNota($notaId);

        if ($importe === null || bccomp($importe, '0', 4) === 0) {
            return;
        }

        $this->balances->registerReturn(
            customerId: (int) $venta->customer_id,
            storeId: (int) $venta->store_id,
            amount: $importe,
            referenceNo: (string) ($venta->invoice_no ?? ''),
            entryDate: $this->ahoraDeLaTienda((int) $venta->store_id),
            saleId: (int) $venta->id,
        );
    }

    /**
     * El TOTAL de la venta, como texto decimal.
     */
    private function totalDeLaVenta(int $saleId): ?string
    {
        $total = $this->connection->table('sale_totals')->where('sale_id', $saleId)->value('grand_total');

        return $total === null ? null : (string) $total;
    }

    /**
     * El TOTAL de la nota de credito, como texto decimal.
     */
    private function totalDeLaNota(int $notaId): ?string
    {
        $total = $this->connection->table('fiscal_documents')->where('id', $notaId)->value('total_amount');

        return $total === null ? null : (string) $total;
    }

    /**
     * El INSTANTE de la anulacion: el ahora de la TIENDA.
     *
     * El reloj del negocio es el de la tienda, no el del servidor. Y es el
     * INSTANTE de la nota de credito y la FECHA DE NEGOCIO del movimiento del
     * libro del cliente: las dos son de HOY, porque la anulacion ocurre hoy.
     */
    private function ahoraDeLaTienda(int $storeId): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zonaDeLaTienda($storeId));
    }

    /**
     * La ZONA HORARIA de la tienda.
     *
     * Es la de `store_settings.timezone`, y si la tienda no la declara, la de
     * la aplicacion: el reloj del negocio es el de la tienda.
     */
    private function zonaDeLaTienda(int $storeId): DateTimeZone
    {
        $timezone = $this->connection->table('store_settings')
            ->where('store_id', $storeId)
            ->value('timezone');

        $nombre = is_string($timezone) ? trim($timezone) : '';

        if ($nombre === '') {
            $nombre = (string) config('app.timezone');
        }

        return new DateTimeZone($nombre);
    }
}
