<?php

namespace App\Support\Fiscal;

use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El ACCESO de un usuario a una tienda.
 *
 * REGLA que fija esta clase: *un documento que pertenece a otra tienda es un
 * 404, no un 403*. Un 403 le dice a un cajero que el comprobante EXISTE, y
 * eso ya es informacion de otra tienda: lo que se responde es que aqui no
 * hay nada.
 *
 * El acceso es POR TIENDA, y se resuelve con la misma regla que la caja:
 *
 *  1. La tienda ACTIVA del usuario: la que resuelve `PosContext`, la misma
 *     que usa la caja. Un usuario sin vinculo con ninguna tienda opera en la
 *     primera tienda activa del sistema.
 *  2. CUALQUIER otra tienda a la que el usuario tenga un vinculo ACTIVO: un
 *     usuario puede vender en varias, y el vinculo es lo que da acceso.
 */
final readonly class StoreAccess
{
    public function __construct(private Connection $connection) {}

    /**
     * ¿El usuario tiene acceso a esta tienda?
     */
    public function allows(?int $userId, int $storeId): bool
    {
        if ($userId === null) {
            return false;
        }

        // 1. La tienda activa del usuario: la MISMA que resuelve la caja.
        try {
            $contexto = new PosContext($this->connection, $userId);

            if ((int) $contexto->store()->id === $storeId) {
                return true;
            }
        } catch (RuntimeException) {
            // Sin tienda activa solo puede valer el vinculo explicito.
        }

        // 2. Una tienda mas a la que el usuario tiene vinculo ACTIVO, y que
        //    esta activa: una tienda desactivada no es una tienda donde se
        //    opera.
        return $this->connection->table('user_stores')
            ->join('stores', 'stores.id', '=', 'user_stores.store_id')
            ->where('user_stores.user_id', $userId)
            ->where('user_stores.store_id', $storeId)
            ->where('user_stores.status', 'active')
            ->where('stores.status', true)
            ->exists();
    }
}
