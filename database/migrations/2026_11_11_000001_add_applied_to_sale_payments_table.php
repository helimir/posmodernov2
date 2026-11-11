<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M41 — `sale_payments.applied`: lo APLICADO de cada pago a la venta.
 *
 * LA VERDAD UNICA del cobro son DOS cifras, y hasta ahora solo habia una:
 *
 *  - `sale_payments.amount` es lo RECIBIDO: lo que el cliente entrego. Es el
 *    dato del Cajero;
 *  - `sale_payments.applied` es lo APLICADO a la venta: lo que ese pago
 *    CUBRE. Es el dato del Dominio.
 *
 * Los dos son el mismo numero cuando el cliente paga justo, y DIFIEREN cuando
 * hay cambio: el cliente entrega 100 por una venta de 58, y lo que entro al
 * cajon son 100 pero lo que cubrio la venta son 58. Sin la segunda cifra, el
 * estado de pago, el desglose de la caja y el arqueo comparaban importes
 * BRUTOS con el total, y un sobrepago (el caso NORMAL, veinte veces al dia)
 * dejaba la venta como no cobrada.
 *
 * LA COLUMNA ES NULLABLE A PROPOSITO: las filas anteriores a esta migracion no
 * la tienen. EL FALLBACK de esas filas es su BRUTO (`amount`), y la razon es
 * que una fila sin `applied` no tiene cambio registrado: el pago se guardo
 * entero y lo que se aplico a la venta fue el pago entero. Los dos lectores
 * (`SalePaymentStatus::forSale()` y `CashDay::receivedByPaymentMethod()`)
 * resuelven el fallback con `COALESCE(applied, amount)`.
 *
 * NO LLEVA INDICE: la columna no se busca por si misma. El desglose de la caja
 * filtra por TIENDA y por FECHA DE NEGOCIO (las columnas de `sales`) y agrega
 * la columna; un indice sobre `applied` no acorta esa consulta. El indice que
 * hace falta, el de `sale_payments.sale_id`, ya existe desde M11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            // Lo APLICADO a la venta: lo que el pago cubre. Null en las filas
            // viejas, y su fallback es el bruto.
            $table->decimal('applied', 25, 4)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropColumn('applied');
        });
    }
};
