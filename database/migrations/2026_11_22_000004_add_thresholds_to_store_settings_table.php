<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M47 — los UMBRALES DE OPERACION por TIENDA.
 *
 * Los dos umbrales que decide el negocio van en la ficha de la tienda
 * (`store_settings`), no en la configuracion global:
 *
 *  - `difference_reason_threshold`: el descuadre a partir del cual el cierre
 *    de caja EXIGE justificacion. Una tienda con dos mil ventas al dia no
 *    justifica cada diferencia de centimos; una tienda de barrio si. La
 *    columna es NULLABLE y su `null` significa "se usa el GLOBAL"
 *    (`config/cash.php`, `difference_reason_threshold`), que es el
 *    comportamiento de siempre;
 *  - `final_consumer_id_threshold`: el total de la venta a partir del cual el
 *    POS EXIGE el documento del consumidor final (la cedula o el pasaporte).
 *    Su `null` significa "no se exige NUNCA": una tienda que no lo declara se
 *    comporta como hasta ahora.
 *
 * Y LOS IMPORTES VIAJAN COMO TEXTO DECIMAL: un `float` no es un dinero, y
 * la columna es `decimal(25,4)`, la misma escala que el resto del dinero del
 * repositorio.
 *
 * Y POR QUE SON COLUMNAS Y NO CONFIGURACION: son una DECISION DE
 * OPERACION DE CADA TIENDA, y una decision por tienda no se puede tener en un
 * fichero de configuracion: habria que desplegar para cambiar la de una
 * tienda, y las tiendas no se despliegan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table): void {
            // El UMBRAL DEL DESCUADRE de la tienda. `null` = el GLOBAL.
            $table->decimal('difference_reason_threshold', 25, 4)->nullable()->after('expiry_alert_enabled');

            // El UMBRAL DEL CONSUMIDOR FINAL de la tienda. `null` = nunca.
            $table->decimal('final_consumer_id_threshold', 25, 4)->nullable()->after('difference_reason_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table): void {
            $table->dropColumn(['difference_reason_threshold', 'final_consumer_id_threshold']);
        });
    }
};
