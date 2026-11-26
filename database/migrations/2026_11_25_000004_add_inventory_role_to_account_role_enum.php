<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M44 — el rol `inventory`: la cuenta donde se asienta el STOCK de la tienda.
 *
 * POR QUE ESTE ROL NUEVO:
 *
 * El catalogo de cuentas YA tiene la cuenta `1300000 Inventario de mercaderia`
 * (tipo `asset`), pero NO habia ningun rol que la atara a una tienda: sin rol,
 * una tienda no puede decir "mi stock se asienta aqui". Y una COMPRA es
 * exactamente eso: existencias que entran y deuda con el proveedor. Sin este
 * rol, el asiento de la compra no tiene DEBE donde ir, y el pasivo del
 * proveedor no tiene HABER.
 *
 * Y EL ROL ES OPCIONAL, como el del capital: no va al conjunto de los roles
 * OBLIGATORIOS del plan completo (`AccountRole::obligatorios()`, que siguen
 * siendo CINCO) ni al del COBRO (`AccountRole::delCobro()`, que siguen siendo
 * CUATRO). La decision de la F2-13b no se toca: NINGUNA TIENDA DEJA DE COBRAR.
 *
 * Y LO QUE NO HACE: el rol no dice como se reconoce el COSTO de las
 * existencias. El repositorio no lo dice, y aqui no se inventa: el asiento de
 * la VENTA (`CheckoutService`) no tiene linea de costo de ventas, asi que la
 * recognizing del costo al vender sigue siendo un hueco declarado.
 *
 * El valor se anade FUERA de la transaccion de la migracion: en PostgreSQL un
 * valor nuevo de un enum no se puede usar en la misma transaccion que lo anade.
 */
return new class extends Migration
{
    /**
     * El DDL de un enum no vive en la transaccion de la migracion.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE account_role ADD VALUE IF NOT EXISTS 'inventory'");
    }

    public function down(): void
    {
        // Un valor de un enum de PostgreSQL NO se puede quitar: el enum de
        // Postgres no tiene `DROP VALUE`, y quitarlo en caliente dejaria filas
        // con un rol que ya no existe. La vuelta atras de esta migracion es,
        // por lo tanto, la de no hacer nada: el rol `inventory` se queda como
        // un rol VALIDO y asignable.
    }
};
