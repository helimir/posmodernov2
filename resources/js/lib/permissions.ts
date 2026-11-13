import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * LOS PERMISOS del usuario, y la pregunta que hace la pantalla.
 *
 * REGLA que fija este fichero: *la UI oculta un boton con el MISMO slug que
 * protege la ruta*. El slug es el del CATALOGO del servidor
 * (`App\Domain\Auth\Enums\Permission`), y la lista de los que tiene el
 * usuario llega en `auth.permissions`.
 *
 * Y OCULTAR NO ES PERMITIR: la comprobacion que vale es la del SERVIDOR, y
 * esto es la cortesia que evita el boton que devuelve un error. El boton
 * escondido y el permiso del servidor son la MISMA regla vista desde dos
 * sitios.
 */
export interface Permissions {
    /** ¿El usuario tiene este permiso? */
    can: (slug: string) => boolean;
    /** Todos los slugs que tiene el usuario. */
    permissions: string[];
}

/**
 * Los permisos del usuario de la sesion.
 */
export function usePermissions(): Permissions {
    const { auth } = usePage<SharedData>().props;

    const permissions = auth?.permissions ?? [];

    return {
        can: (slug: string) => permissions.includes(slug),
        permissions,
    };
}
