<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El MIDDLEWARE del permiso: una ruta exige un permiso, y el permiso se
 * DECLARA EN LA RUTA.
 *
 * REGLA que fija este middleware: *el permiso de una ruta vive junto a la
 * ruta, no dentro del controlador*. Asi el inventario de "que protege cada
 * ruta" se lee de un vistazo, en el fichero de rutas, y no hay que abrir
 * veintidos controladores para saber que protege que.
 *
 * Y LA COMPROBACION ESTA EN EL SERVIDOR: el boton que se oculta en la
 * interfaz es una cortesia, y el permiso es la ley. El defecto del legacy no
 * se hereda: alli el boton se ocultaba y el formatter no comprobaba nada, y
 * quien no tenia el permiso se encontraba un 422 al pulsar.
 *
 * EL ALCANCE DEL MIDDLEWARE:
 *
 *  - Sin sesion, la respuesta es un 401: no hay quien decidir.
 *  - Con sesion y sin permiso, la respuesta es un 403 con el `errorMsg` del
 *    contrato de la API, o la pagina de error cuando la peticion es de
 *    navegador.
 *  - Con el permiso, la peticion sigue su curso.
 *
 * Y EL PERMISO QUE NO ESTA EN EL CATALOGO NO CONCEDE NADA: un slug
 * equivocado no abre la puerta a nadie, ni siquiera al superusuario. Un
 * permiso que no existe no es un permiso.
 */
final class EnsurePermission
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  ...$permissions  Los permisos que conceden el paso: basta
     *                                  con uno.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return $this->sinSesion();
        }

        foreach ($permissions as $permission) {
            if ($usuario->can($permission)) {
                return $next($request);
            }
        }

        return $this->prohibido($request, $permissions);
    }

    /**
     * La respuesta SIN SESION.
     */
    private function sinSesion(): JsonResponse
    {
        return response()->json([
            'errorMsg' => 'No hay sesion abierta: esta pantalla necesita un usuario con permiso.',
        ], 401);
    }

    /**
     * La respuesta SIN PERMISO.
     *
     * La API recibe el `errorMsg` del contrato de siempre, y el navegador
     * recibe la pagina de error: una pantalla de Inertia no sabe leer un
     * JSON, y un API si.
     *
     * @param  list<string>  $permissions
     */
    private function prohibido(Request $request, array $permissions): Response
    {
        $mensaje = 'Tu rol no tiene el permiso '.implode(' ni el permiso ', $permissions).'.';

        if ($request->expectsJson()) {
            return response()->json(['errorMsg' => $mensaje], 403);
        }

        abort(403, $mensaje);
    }
}
