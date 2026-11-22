<?php

namespace App\Support\Store;

use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;

/**
 * Los UMBRALES DE OPERACION de una TIENDA.
 *
 * REGLA que fija esta clase: *el umbral de la tienda MANDA si lo declara, y
 * si no, manda el GLOBAL*. Es la precedencia, y es la misma para los dos
 * umbrales: el valor de la ficha de la tienda (`store_settings`) si no es
 * `null`, y el valor de la configuracion si la tienda no declara ninguno.
 *
 * Y POR QUE LA PRECEDENCIA ESTA EN UN SITIO Y NO EN DOS: el descuadre de caja
 * y el documento del consumidor final los lee el dominio, y dos lecturas del
 * mismo dato son dos verdades que divergen. Aqui se resuelve UNA vez, y el
 * que la necesita la pregunta.
 *
 * Y LOS IMPORTES VIAJAN COMO TEXTO DECIMAL: un `float` no es un dinero. El
 * `null` de la columna es un dato con significado (`null` = "no lo declara"),
 * no un importe vacio.
 */
final readonly class StoreThresholds
{
    /**
     * La columna del umbral del DESCUADRE de la tienda.
     */
    private const COLUMNA_DESCUADRE = 'difference_reason_threshold';

    /**
     * La columna del umbral del CONSUMIDOR FINAL de la tienda.
     */
    private const COLUMNA_CONSUMIDOR_FINAL = 'final_consumer_id_threshold';

    /**
     * Las columnas que esta clase sabe leer. Es una LISTA CERRADA: un
     * identificador de columna no se interpola sin comprobarlo antes.
     *
     * @var list<string>
     */
    private const COLUMNAS = [
        self::COLUMNA_DESCUADRE,
        self::COLUMNA_CONSUMIDOR_FINAL,
    ];

    public function __construct(private Connection $connection) {}

    /**
     * El UMBRAL DEL DESCUADRE de la tienda: el que ella declara, o el GLOBAL
     * de la configuracion (`config/cash.php`) si no declara ninguno.
     *
     * REGLA que fija este metodo: *el umbral de la tienda manda sobre el
     * global*. Una tienda con dos mil ventas al dia no justifica cada
     * diferencia de centimos; una tienda de barrio si. Y una tienda que no
     * declara ninguno se comporta como hasta ahora: el global.
     */
    public function umbralDeDescuadre(int $storeId): string
    {
        $deLaTienda = $this->valor($storeId, self::COLUMNA_DESCUADRE);

        if ($deLaTienda !== null) {
            return $deLaTienda;
        }

        return PosAmount::text(config('cash.difference_reason_threshold', '0.0100'));
    }

    /**
     * El UMBRAL DEL CONSUMIDOR FINAL de la tienda, o `null` si no lo
     * declara.
     *
     * Y AQUI NO HAY UN GLOBAL: el `null` de la columna significa "NO SE EXIGE
     * NUNCA", y es el comportamiento de siempre. El umbral global del
     * descuadre existe porque el descuadre siempre ha tenido uno; el del
     * consumidor final es nuevo, y nace por tienda.
     */
    public function umbralDeConsumidorFinal(int $storeId): ?string
    {
        return $this->valor($storeId, self::COLUMNA_CONSUMIDOR_FINAL);
    }

    /**
     * El valor de un umbral de la ficha de la tienda, o `null` si no lo
     * declara.
     */
    private function valor(int $storeId, string $columna): ?string
    {
        if (! in_array($columna, self::COLUMNAS, true)) {
            return null;
        }

        $valor = $this->connection->table('store_settings')
            ->where('store_id', $storeId)
            ->value($columna);

        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        return PosAmount::text($texto);
    }
}
