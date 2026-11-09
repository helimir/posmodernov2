<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Sale;
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
            ->get()
            ->map(fn (Sale $venta): array => [
                'id' => (int) $venta->id,
                'invoice_no' => $venta->invoice_no,
                'sale_date' => $venta->sale_date?->format('Y-m-d'),
                'customer' => $venta->customer?->name,
                'status' => (string) $venta->status,
                'grand_total' => $venta->total?->grand_total,
                'items_count' => (int) $venta->items_count,
            ])
            ->values()
            ->all();

        return Inertia::render('sales/index', [
            'sales' => $ventas,
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
            ->with(['payments.paymentMethod:id,name'])
            ->find($sale);

        abort_if($model === null, 404);

        $asiento = LedgerEntry::query()
            ->with(['lines', 'entryType:id,code,name'])
            ->where('reference_type', 'sale')
            ->where('reference_id', $model->id)
            ->first();

        return Inertia::render('sales/show', [
            'sale' => [
                'id' => (int) $model->id,
                'invoice_no' => $model->invoice_no,
                'sale_date' => $model->sale_date?->format('Y-m-d'),
                'status' => (string) $model->status,
                'customer' => $model->customer?->name,
                'store' => $model->store?->name,
                'created_at' => $model->created_at?->format('Y-m-d H:i:s'),
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
                'payments' => $model->payments->map(fn ($payment): array => [
                    'id' => (int) $payment->id,
                    'payment_method' => $payment->paymentMethod?->name,
                    'amount' => (string) $payment->amount,
                ])->values()->all(),
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
}
