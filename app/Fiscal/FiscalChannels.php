<?php

namespace App\Fiscal;

use App\Domain\Fiscal\Enums\ProviderKind;

/**
 * El CATALOGO de canales fiscales.
 *
 * REGLA que fija esta clase: *un canal sin implementacion NO se emula*. El
 * dominio lo dice al resolver (`FiscalProviderResolver`): un proveedor de
 * tipo `pac` o `portal` no tiene a ningun actor externo contra el que
 * quedarse, y un actor emulado emitiria comprobantes que no existen.
 *
 * Y LA PANTALLA NO FINGE: la administracion fiscal muestra el canal de cada
 * proveedor y AVISA de que ese canal no emite todavia. Un canal declarado y
 * no implementado es una DECLARACION PENDIENTE, no un fallo silencioso.
 *
 * Y LA LISTA DE CANALES CON IMPLEMENTACION esta aqui, y no en el
 * resolutor, porque el resolutor la tiene en un `match` PRIVADO: la
 * administracion necesita PREGUNTAR y el dominio no expone la pregunta. Si
 * el adaptador del canal externo llega, esta lista y el `match` del
 * resolutor cambian juntos.
 */
final class FiscalChannels
{
    /**
     * Los canales que NO tienen implementacion en esta version.
     *
     * @return list<string>
     */
    public static function sinImplementacion(): array
    {
        return [
            ProviderKind::Pac->value,
            ProviderKind::Portal->value,
        ];
    }

    /**
     * El canal TIENE implementacion, o no la tiene.
     *
     * REGLA que fija este metodo: *preguntar por el canal NO es emitir por
     * el canal*. Un canal sin implementacion se puede preguntar sin que la
     * pregunta sea un fallo, y la pantalla lo necesita para avisar.
     */
    public static function tieneImplementacion(string $kind): bool
    {
        return ! in_array($kind, self::sinImplementacion(), true);
    }

    /**
     * El AVISO de un canal que no puede emitir, o `null` si el canal puede.
     *
     * Es un texto de PANTALLA, y por eso esta en ingles.
     */
    public static function aviso(string $kind): ?string
    {
        if (self::tieneImplementacion($kind)) {
            return null;
        }

        return "The channel '{$kind}' has no implementation in this version: it cannot emit. "
            .'The provider is declared, but no document can go through it until the channel is implemented.';
    }
}
