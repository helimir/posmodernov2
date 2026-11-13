<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $usuario = $request->user();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $usuario,
                // Los PERMISOS del usuario: la lista de sus slugs.
                //
                // REGLA que fija esta clase: *la UI oculta un boton con el
                // MISMO slug que protege la ruta*. Para eso la pantalla
                // necesita la lista, y la lista sale del MISMO catalogo que
                // aplica el middleware: no hay una segunda verdad.
                'permissions' => $usuario === null ? [] : $usuario->permissions(),
                'role' => $usuario === null ? null : [
                    'id' => $usuario->role === null ? null : (int) $usuario->role->id,
                    'name' => $usuario->role === null ? null : (string) $usuario->role->name,
                    'slug' => $usuario->role === null ? null : (string) $usuario->role->slug,
                ],
                'is_superuser' => $usuario !== null && $usuario->isSuperuser(),
            ],
        ]);
    }
}
