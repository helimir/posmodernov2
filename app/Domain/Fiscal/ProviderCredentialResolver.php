<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\Enums\ProviderKind;
use App\Models\FiscalProvider;
use RuntimeException;

/**
 * La DEMANDA de una CREDENCIAL de un proveedor fiscal.
 *
 * REGLA que fija esta clase: *el secreto NUNCA esta en la base de datos*. La
 * fila del proveedor declara el PREFIJO de la variable de entorno (la misma
 * convencion que `sms_providers`), y el secreto se resuelve en runtime, en el
 * momento de usarlo.
 *
 * Y REGLA que fija la SEGUNDA: *un secreto ausente NO es un error de
 * arranque*. El proveedor se resuelve sin credenciales —el canal local no las
 * tiene— y el que las necesita lo dice cuando las pide. Pero una fila SIN
 * prefijo, usada por un canal que las necesita, es un error de
 * CONFIGURACION, y se dice como tal.
 *
 * Y EL MENSAJE dice QUE credencial falta y DE QUE variable sale: el NOMBRE de
 * la variable, nunca su valor. Un secreto en un mensaje de error es un
 * secreto en un log.
 */
final class ProviderCredentialResolver
{
    /**
     * La credencial que el canal necesita, o un ERROR UTIL.
     *
     * @throws RuntimeException Cuando el canal LOCAL la pide (no hay a quien
     *                          autenticarse), cuando la fila NO declara
     *                          prefijo (error de configuracion) o cuando la
     *                          variable de entorno no esta definida.
     */
    public function resolve(FiscalProvider $provider, string $variable): string
    {
        // El canal LOCAL no se autentica: es local.
        if (ProviderKind::tryFrom((string) $provider->kind) === ProviderKind::Printer) {
            throw new RuntimeException(
                "El proveedor '{$provider->code}' es el canal local: no necesita credenciales."
            );
        }

        $nombre = $provider->credentialVariable($variable);

        // Una fila SIN prefijo, usada por un canal que necesita credenciales,
        // es un error de CONFIGURACION: no es que falte el secreto, es que
        // nadie ha declarado de donde sale.
        if ($nombre === null) {
            throw new RuntimeException(
                "El proveedor '{$provider->code}' no declara el prefijo de sus credenciales "
                ."('credential_env_prefix' en null): la credencial '{$variable}' no se puede resolver."
            );
        }

        $valor = $provider->credential($variable);

        if ($valor === null || $valor === '') {
            throw new RuntimeException(
                "Al proveedor '{$provider->code}' le falta la credencial '{$variable}': "
                ."no esta definida la variable de entorno '{$nombre}'."
            );
        }

        return $valor;
    }
}
