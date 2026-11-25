<?php

namespace App\Domain\Reports;

use App\Support\Pos\PosContext;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * El AMBITO de un reporte: las tiendas que el reporte puede leer.
 *
 * REGLA que fija esta clase: *un reporte no puede enseñar datos de otra
 * tienda*, y el ambito es el del ROL, no el de la pantalla. La fuente es
 * `PosContext::scopeIds()`: el mismo criterio que el ALCANCE POR DEFECTO de
 * las ventas (`App\Support\Pos\StoreScope`), el que usa la caja, la cola de
 * impresion y el POS. Un rol SIN ambito alcanza todas las tiendas (el
 * superusuario y el contador), y un rol CON ambito alcanza las suyas y solo
 * las suyas.
 *
 * Y NO SE DUPLICA EL ALCANCE: las ventas se leen con el modelo `Sale`, que
 * ya lleva su alcance global, y esta clase no le anade un segundo filtro
 * encima. Para el resto de las tablas (el libro, el Aging, los lotes, los
 * cierres) el ambito se aplica aqui, con el MISMO criterio.
 *
 * Y EL ALCANCE SIN TIENDAS ES "TODAS": una lista vacia no es un alcance que
 * no muestra nada, es un alcance que no acota. Y el alcance sin ninguna
 * tienda tampoco es un error: un rol que no puede ver ninguna tienda no ve
 * datos, y eso es lo que tiene que ver.
 */
final readonly class ReportScope
{
    /**
     * @param  list<int>|null  $storeIds  Las tiendas del ambito, o `null`
     *                                    cuando el alcance son todas.
     */
    private function __construct(private ?array $storeIds) {}

    /**
     * Un AMBITO a partir de una lista de tiendas.
     *
     * @param  list<int>|null  $storeIds  Las tiendas del ambito, o `null`
     *                                    cuando el alcance son todas.
     */
    public static function of(?array $storeIds): self
    {
        if ($storeIds === null) {
            return new self(null);
        }

        $ids = array_values(array_unique(array_map('intval', $storeIds)));

        sort($ids);

        return new self($ids === [] ? null : $ids);
    }

    /**
     * El AMBITO del ROL de quien pregunta, resuelto por el CONTEXTO.
     */
    public static function fromContext(PosContext $contexto): self
    {
        return self::of($contexto->scopeIds());
    }

    /**
     * Si el alcance son TODAS las tiendas.
     */
    public function isUnlimited(): bool
    {
        return $this->storeIds === null;
    }

    /**
     * Las TIENDAS del ambito, o `null` cuando el alcance son todas.
     *
     * @return list<int>|null
     */
    public function storeIds(): ?array
    {
        return $this->storeIds;
    }

    /**
     * ACOTA una consulta a las tiendas del ambito.
     *
     * REGLA que fija este metodo: *el filtro se anade al AMBITO, no al
     * nombre de una tienda*. El reporte no sabe el nombre de ninguna tienda:
     * solo sabe las que puede leer.
     *
     * @param  EloquentBuilder|QueryBuilder  $query  La consulta a acotar.
     * @param  string  $column  La columna de la tienda de la fila.
     */
    public function restrict(EloquentBuilder|QueryBuilder $query, string $column = 'store_id'): void
    {
        if ($this->storeIds === null) {
            return;
        }

        $query->whereIn($column, $this->storeIds);
    }
}
