<?php

namespace App\Http\Controllers;

use App\Domain\Fiscal\SaleFiscalStatus;
use App\Domain\Sales\SalePayments;
use App\Domain\Sales\SaleVoidRequests;
use App\Models\LedgerEntry;
use App\Models\PrintTarget;
use App\Models\Sale;
use Illuminate\Database\Connection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La VISTA de las ventas: la costura en el navegador.
 *
 * La Fase 0 tiene el dominio entero (motor de impuestos, checkout atomico,
 * FIFO de lotes, libro contable, caja por fecha de negocio) y no hay forma de
 * VERLO: solo hay pruebas. Estas dos paginas son esa forma: la LISTA de las
 * ventas mas recientes y el DETALLE de una de ellas, con sus lineas, sus
 * totales, sus pagos y su asiento contable.
 *
 * Son de SOLO LECTURA: no escriben nada, no cambian el estado de una venta y
 * no tocan la numeracion. Ver una venta no puede cambiar lo que la venta es.
 * El REINTENTO de la emision fiscal NO vive aqui: es una escritura, y tiene
 * su propia ruta (`FiscalIssuanceController`). Y la ANULACION tampoco: es
 * otra escritura, con su nota de credito, y vive en `SaleVoidController`.
 *
 * Y LO QUE LA FICHA ENSEÑA DE UNA VENTA ANULADA: el estado, el motivo y su
 * nota de credito. La anulacion no es un estado invisible: una venta
 * anulada que parece una venta corriente es una venta que nadie puede
 * defender despues.
 *
 * Los importes viajan como TEXTO decimal: las columnas son `numeric(25,4)` y
 * un `float` no es un dinero. La pagina los enseña tal y como estan, sin
 * redondearlos otra vez.
 */
class SalesController extends Controller
{
    /**
     * Cuantas ventas devuelve la lista.
     *
     * Es un tope, no una paginacion: la lista es el RECENTRO de la caja, y
     * una pagina que trae cincuenta ventascabecera es la que se lee.
     */
    private const LIMITE = 50;

    public function __construct(
        private readonly SaleFiscalStatus $fiscal,
        private readonly SalePayments $payments,
        private readonly SaleVoidRequests $requests,
        private readonly Connection $connection,
    ) {}

    /**
     * La LISTA de ventas, la mas reciente primero.
     *
     * El orden es por FECHA DE NEGOCIO y, a igualdad, por id: la fecha de
     * negocio es la que manda la caja, y el id desempata las ventas del mismo
     * dia sin que el orden dependa del plan de ejecucion.
     */
    public function index(): Response
    {
        $ventas = Sale::query()
            ->with(['customer:id,name'])
            ->with('total')
            ->withCount('items')
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        // El estado fiscal de TODAS las ventas, en las consultas que hacen
        // falta: una pregunta por venta en una pagina de cincuenta ventas es
        // cincuenta veces la pregunta que se podia hacer una vez.
        $estados = $this->fiscal->forSales(
            $ventas->map(fn (Sale $venta): int => (int) $venta->id)->all()
        );

        // Lo APLICADO de las ventas de la pagina, tambien en UNA consulta: el
        // listado es el RECENTRO de la caja, y lo que cada venta COBRIO es
        // una pregunta de todas, no una por venta.
        $aplicados = $this->payments->appliedTotals(
            $ventas->map(fn (Sale $venta): int => (int) $venta->id)->all()
        );

        return Inertia::render('sales/index', [
            'sales' => $ventas->map(fn (Sale $venta): array => [
                'id' => (int) $venta->id,
                'invoice_no' => $venta->invoice_no,
                'sale_date' => $venta->sale_date?->format('Y-m-d'),
                'customer' => $venta->customer?->name,
                'status' => (string) $venta->status,
                'grand_total' => $venta->total?->grand_total,
                // Lo APLICADO de la venta: la verdad de lo que cobro. Una
                // venta sin pagos tiene cero aplicado, no una cifra
                // desconocida.
                'applied_total' => $aplicados[(int) $venta->id] ?? '0.0000',
                'items_count' => (int) $venta->items_count,
                'fiscal' => $this->estadoFiscalPara($estados[(int) $venta->id] ?? null),
            ])->values()->all(),
        ]);
    }

    /**
     * El DETALLE de una venta: la cabecera, las lineas, los totales, los
     * pagos y el asiento contable (si lo tiene).
     *
     * Una venta que no existe es un 404: no se inventa una pantalla de una
     * venta que no hay.
     */
    public function show(int $sale): Response
    {
        $model = Sale::query()
            ->with(['customer:id,name', 'store:id,name'])
            ->with(['items.product:id,code,name'])
            ->with('total')
            ->find($sale);

        abort_if($model === null, 404);

        $asiento = LedgerEntry::query()
            ->with(['lines', 'entryType:id,code,name'])
            ->where('reference_type', 'sale')
            ->where('reference_id', $model->id)
            ->first();

        $estados = $this->fiscal->forSales([(int) $model->id]);

        // El HISTORIAL de las peticiones de anulacion de esta venta: de la
        // mas nueva a la mas vieja. La ULTIMA es la que explica que paso.
        $historial = $this->requests->history((int) $model->id);

        // Los PAGOS, con sus TRES cifras, y el RESUMEN de la venta: lo
        // aplicado es la verdad, y su diferencia con el total es el hueco que
        // la pantalla tiene que enseñar.
        $resumen = $this->payments->summary(
            (int) $model->id,
            $model->total === null ? null : (string) $model->total->grand_total,
        );

        return Inertia::render('sales/show', [
            'sale' => [
                'id' => (int) $model->id,
                'invoice_no' => $model->invoice_no,
                'sale_date' => $model->sale_date?->format('Y-m-d'),
                'status' => (string) $model->status,
                'customer' => $model->customer?->name,
                'store' => $model->store?->name,
                'created_at' => $model->created_at?->format('Y-m-d H:i:s'),
                'fiscal' => $this->estadoFiscalPara($estados[(int) $model->id] ?? null),
                'print_targets' => $this->destinosDeImpresion((int) $model->store_id),
                'lines' => $model->items->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'product_name' => (string) ($item->product?->name ?? ''),
                    'product_code' => $item->product?->code,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'discount' => (string) $item->discount,
                    'base_amount' => (string) $item->base_amount,
                    'tax_rate_snapshot' => $item->tax_rate_snapshot === null ? null : (string) $item->tax_rate_snapshot,
                    'evidence_status' => $item->evidence_status,
                    'tax_amount' => (string) $item->tax_amount,
                    'line_total' => (string) $item->line_total,
                ])->values()->all(),
                'totals' => $model->total === null ? null : [
                    'sub_total' => (string) $model->total->sub_total,
                    'discount' => (string) $model->total->discount,
                    'tax_total' => (string) $model->total->tax_total,
                    'rounding_adjustment' => (string) $model->total->rounding_adjustment,
                    'grand_total' => (string) $model->total->grand_total,
                    'evidence_status' => $model->total->evidence_status,
                ],
                'payments' => $this->payments->forSale((int) $model->id),
                'payment_summary' => [
                    'received_total' => $resumen->receivedTotal,
                    'applied_total' => $resumen->appliedTotal,
                    'change_total' => $resumen->changeTotal,
                    'total' => $resumen->total,
                    'difference' => $resumen->difference,
                    'matches_total' => $resumen->matchesTotal,
                ],
                // La ANULACION: el bloque que aparece cuando la venta esta
                // anulada. Es `null` en una venta corriente.
                'void' => $this->anulacion($model),
                // La SOLICITUD de anulacion: la peticion que ha hecho el
                // cajero para que le anulen la venta. Es `null` cuando no
                // hay ninguna.
                'void_request' => $this->solicitudDeAnulacion($historial),
                // Y el HISTORIAL de las peticiones de esta venta, de la mas
                // nueva a la mas vieja: una peticion rechazada no se pierde
                // porque venga otra.
                'void_request_history' => $historial,
                'ledger' => $asiento === null ? null : [
                    'id' => (int) $asiento->id,
                    'entry_type' => (string) ($asiento->entryType?->code ?? ''),
                    'business_date' => $asiento->business_date?->format('Y-m-d'),
                    'amount' => (string) $asiento->amount,
                    'description' => $asiento->description,
                    'lines' => $asiento->lines->map(fn ($line): array => [
                        'account_code' => (string) $line->account_code,
                        'debit' => (string) $line->debit,
                        'credit' => (string) $line->credit,
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    /**
     * El ESTADO FISCAL de una venta, tal como lo ve la pantalla.
     *
     * El CONTRATO es el mismo que el de la respuesta del cobro (`fiscal`),
     * mas lo que la pantalla necesita y el cobro no: el enlace al
     * comprobante y si el reintento tiene sentido.
     *
     *  - `status`: `issued` (emitido), `failed` (fallido, con su motivo) o
     *    `not_emitted` (no emitido).
     *  - `document_id`: el comprobante, si lo hay.
     *  - `control_number`: el numero de control, si se emitio.
     *  - `message`: el motivo del fallo, si lo hubo.
     *  - `receipt_url`: el enlace al comprobante, si se emitio.
     *  - `can_retry`: si tiene sentido reintentar la emision.
     *
     * @param  array{status: string, document_id: int|null, control_number: string|null, message: string|null}|null  $estado
     * @return array{status: string, document_id: int|null, control_number: string|null, message: string|null, receipt_url: string|null, can_retry: bool}
     */
    private function estadoFiscalPara(?array $estado): array
    {
        $estado ??= [
            'status' => 'not_emitted',
            'document_id' => null,
            'control_number' => null,
            'message' => null,
        ];

        $emitido = $estado['status'] === 'issued' && $estado['document_id'] !== null;

        return [
            'status' => (string) $estado['status'],
            'document_id' => $estado['document_id'],
            'control_number' => $estado['control_number'],
            'message' => $estado['message'],
            'receipt_url' => $emitido
                ? route('comprobantes.show', ['documento' => $estado['document_id']], false)
                : null,
            // Solo un fallo se reintenta: una venta ya emitida no se vuelve a
            // emitir, y una venta sin emitir todavia no tiene nada que
            // reintentar.
            'can_retry' => $estado['status'] === 'failed',
        ];
    }

    /**
     * La ANULACION de la venta, tal como la ve la pantalla.
     *
     * REGLA que fija este metodo: *una venta anulada se VE anulada*. El
     * bloque dice que la venta esta anulada, POR QUE, que comprobante quedo
     * sin efecto y que NOTA DE CREDITO lo deja sin efecto. Una venta anulada
     * que parece una venta corriente es una venta que nadie puede defender
     * despues.
     *
     * Y EL MOTIVO se lee de la traza: primero el EVENTO FISCAL (que la
     * anulacion escribio en la MISMA transaccion) y, si no hay comprobante
     * que anular, la AUDITORIA de negocio. El motivo de una anulacion no se
     * inventa: o esta escrito o no se enseño.
     *
     * @return array{
     *     reason: string|null,
     *     voided_at: string|null,
     *     document: array{id: int, number: string, control_number: string|null}|null,
     *     note: array{id: int, number: string, issue_date: string|null}|null
     * }|null
     */
    private function anulacion(Sale $model): ?array
    {
        if ((string) $model->status !== 'cancelled') {
            return null;
        }

        $documentoId = $model->fiscal_document_id === null ? null : (int) $model->fiscal_document_id;

        $documento = $documentoId === null
            ? null
            : $this->connection->table('fiscal_documents')->where('id', $documentoId)->first();

        $evento = $documentoId === null
            ? null
            : $this->connection->table('fiscal_document_events')
                ->where('fiscal_document_id', $documentoId)
                ->where('action', 'cancel')
                ->orderByDesc('id')
                ->first();

        $auditoria = $this->connection->table('audit_logs')
            ->where('action', 'sale.voided')
            ->where('entity_type', 'sale')
            ->where('entity_id', (int) $model->id)
            ->orderByDesc('id')
            ->first();

        $motivo = $this->motivoDe($evento?->payload, $auditoria?->context);

        $anuladaEl = $documento?->voided_at !== null
            ? (string) $documento->voided_at
            : ($auditoria?->created_at === null ? null : (string) $auditoria->created_at);

        // El COMPROBANTE que quedo sin efecto: solo cuando esta ANULADO. Una
        // venta sin comprobante emitido no tiene ninguna factura que anular.
        $anulado = $documento !== null && (string) $documento->status === 'void' ? $documento : null;

        return [
            'reason' => $motivo,
            'voided_at' => $anuladaEl,
            'document' => $anulado === null ? null : [
                'id' => (int) $anulado->id,
                'number' => $this->numeroDelDocumento($anulado),
                'control_number' => $documentoId === null ? null : $this->controlNumber($documentoId),
            ],
            'note' => $this->notaDeCredito($documentoId),
        ];
    }

    /**
     * El MOTIVO de la anulacion: el de la traza fiscal o el de la auditoria.
     *
     * @param  mixed  $payload  La carga util del evento fiscal.
     * @param  mixed  $contexto  El contexto de la auditoria de negocio.
     */
    private function motivoDe(mixed $payload, mixed $contexto): ?string
    {
        $delEvento = $this->motivoDeLaCarga($payload, 'reason');

        if ($delEvento !== null) {
            return $delEvento;
        }

        return $this->motivoDeLaCarga($contexto, 'reason');
    }

    /**
     * El MOTIVO de una carga util en JSON.
     */
    private function motivoDeLaCarga(mixed $carga, string $clave): ?string
    {
        if ($carga === null) {
            return null;
        }

        if (is_string($carga)) {
            $carga = json_decode($carga, true);
        }

        if (! is_array($carga)) {
            return null;
        }

        $motivo = $carga[$clave] ?? null;

        return $motivo === null ? null : (string) $motivo;
    }

    /**
     * La SOLICITUD de anulacion de la venta, tal y como la ve la pantalla.
     *
     * REGLA que fija este metodo: *la pantalla ENSENA la ultima peticion y
     * su HISTORIAL*. La ultima es la que explica que paso: la que espera, la
     * que se rechazo o la que se retirio. Y el historial entero, porque una
     * venta puede tener una peticion rechazada y otra nueva, y las dos
     * existen.
     *
     * Y EL ESTADO QUE SE ENSENA NO ES EL QUE SE GUARDA: una peticion
     * pendiente de una venta anulada esta ATENDIDA, y eso lo dice la venta.
     *
     * @param  list<array<string, mixed>>  $historial  Las peticiones de la
     *                                                 venta, de la mas nueva a
     *                                                 la mas vieja.
     * @return array<string, mixed>|null
     */
    private function solicitudDeAnulacion(array $historial): ?array
    {
        return $historial === [] ? null : $historial[0];
    }

    /**
     * La NOTA DE CREDITO que deja sin efecto al comprobante de la venta.
     *
     * La nota es un documento DERIVADO: se encuentra por el VINCULO que
     * dejo el dominio (`corrects`), no por buscar "una nota".
     *
     * @return array{id: int, number: string, issue_date: string|null}|null
     */
    private function notaDeCredito(?int $documentoId): ?array
    {
        if ($documentoId === null) {
            return null;
        }

        $nota = $this->connection->table('fiscal_document_links')
            ->join(
                'fiscal_documents',
                'fiscal_documents.id',
                '=',
                'fiscal_document_links.fiscal_document_id'
            )
            ->where('fiscal_document_links.parent_document_id', $documentoId)
            ->where('fiscal_document_links.link_type', 'corrects')
            ->where('fiscal_documents.document_type', 'credit_note')
            ->orderByDesc('fiscal_documents.id')
            ->first();

        if ($nota === null) {
            return null;
        }

        return [
            'id' => (int) $nota->id,
            'number' => $this->numeroDelDocumento($nota),
            'issue_date' => $nota->issue_date === null ? null : (string) $nota->issue_date,
        ];
    }

    /**
     * El NUMERO COMPLETO de un documento fiscal: el prefijo de su serie mas
     * el secuencial.
     *
     * Un secuencial suelto ("3") no identifica un documento fiscal: hay
     * varias series y sus prefijos son distintos.
     */
    private function numeroDelDocumento(object $documento): string
    {
        $numero = (string) $documento->number;

        if ($documento->series_id === null) {
            return $numero;
        }

        $prefijo = $this->connection->table('fiscal_series')
            ->where('id', (int) $documento->series_id)
            ->value('prefix');

        if ($prefijo === null || trim((string) $prefijo) === '') {
            return $numero;
        }

        return trim((string) $prefijo).$numero;
    }

    /**
     * El NUMERO DE CONTROL de un documento, si lo tiene.
     *
     * El control tiene una UNICA sede: la tabla de asignaciones.
     */
    private function controlNumber(int $documentId): ?string
    {
        $control = $this->connection->table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)
            ->value('control_number');

        return $control === null ? null : (string) $control;
    }

    /**
     * Los DESTINOS de impresion de la tienda, los que escriben bytes.
     *
     * El transporte `browser` no se ofrece: no hay bytes que escribir, y el
     * navegador es el destino.
     *
     * @return list<array{id: int, name: string, transport: string}>
     */
    private function destinosDeImpresion(int $storeId): array
    {
        $filas = $this->connection->table('print_targets')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereIn('transport', PrintTarget::TRANSPORTES_FISICOS)
            ->orderBy('name')
            ->get(['id', 'name', 'transport']);

        $destinos = [];

        foreach ($filas as $fila) {
            $destinos[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'transport' => (string) $fila->transport,
            ];
        }

        return $destinos;
    }
}
