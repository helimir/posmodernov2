<?php

namespace App\Support\Pos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * El ALCANCE DE TIENDAS por defecto: el scope global que acota las lecturas
 * de una tabla por tienda al AMBITO del rol de quien pregunta.
 *
 * REGLA que fija esta clase: *una venta de otra tienda no existe para quien
 * pregunta*. Y NO es un `where` que cada pantalla recuerda: es el ALCANCE de
 * la propia tabla, y por eso un `Sale::find()` de otra tienda no puede
 * saltarselo por descuido, y una pantalla nueva no puede olvidarlo.
 *
 * Y EL CRITERIO ES UNO SOLO: `PosContext::scopeIds()`, el ambito de tiendas
 * del ROL, el mismo que usa la caja, la cola de impresion, el POS y la cola
 * de las solicitudes de anulacion. El ambito es del ROL y no del usuario: un
 * rol SIN ambito alcanza todas las tiendas (el superusuario y el contador), y
 * un rol CON ambito alcanza las suyas y solo las suyas.
 *
 * Y SIN SESION NO HAY ALCANCE: una consola o una cola no preguntan en nombre
 * de nadie, y no hay a quien filtrar. En una peticion web la pantalla exige
 * sesion, asi que el filtro siempre esta.
 *
 * Y LA TABLA TIENE QUE TENER LA COLUMNA `store_id`: el alcance se aplica a la
 * columna de la tienda de la fila, y una tabla sin esa columna no es una
 * tabla por tienda.
 */
final readonly class StoreScope implements Scope
{
    /**
     * Aplica el ALCANCE a la consulta.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tiendas = $this->tiendasDelAlcance($model);

        if ($tiendas === null) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('store_id'), $tiendas);
    }

    /**
     * Las TIENDAS del alcance de quien pregunta.
     *
     * Y LA CONEXION ES LA DEL MODELO: el alcance se resuelve en la misma
     * conexion por la que se pregunta, y no en otra, que podria no ver lo
     * que esta acaba de escribir.
     *
     * @param  Model  $model  El modelo que se consulta.
     * @return list<int>|null Las tiendas del ambito, o `null` si el alcance
     *                        son todas.
     */
    private function tiendasDelAlcance(Model $model): ?array
    {
        $usuarioId = Auth::id();

        if ($usuarioId === null) {
            return null;
        }

        return (new PosContext($model->getConnection(), (int) $usuarioId))->scopeIds();
    }
}
