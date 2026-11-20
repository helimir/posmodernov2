<?php

namespace App\Print;

use Carbon\CarbonInterface;

/**
 * El TOKEN del agente LAN: un hash de CONTRASENA, no una huella.
 *
 * REGLA que fija esta clase: *el token del agente se guarda como un hash de
 * contrasena (argon2id) y se verifica con `password_verify`*.
 *
 * El token es la credencial de UNA CAJA, y por eso no puede ser un hash
 * rapido: un SHA-256 se rompe por fuerza bruta a una velocidad que no es un
 * problema para quien tenga una GPU. Un hash de contrasena es lento a proposito
 * y lleva su propia sal.
 *
 * Y POR ESO LA BUSQUEDA YA NO ES POR EL HASH: argon2id no se indexa (cada
 * hash lleva una sal distinta, asi que dos agentes nunca comparten el mismo
 * hash). El agente se localiza por su identificador y su token se verifica
 * contra el hash de ESE agente.
 *
 * Esta clase es PURO: no toca la base de datos. Quien escribe, es quien decide
 * el destino del hash.
 */
final class PrintAgentToken
{
    /**
     * El HASH del token: argon2id con su sal y sus parametros.
     *
     * Si argon2id no estuviera disponible, se cae a bcrypt: lo que NO vale es
     * un hash rapido.
     */
    public function hash(string $token): string
    {
        return password_hash($token, $this->algoritmo());
    }

    /**
     * ¿Coincide el token recibido con el hash guardado?
     *
     * `password_verify` compara en TIEMPO CONSTANTE por su cuenta: no filtra
     * ni por el token ni por el hash.
     *
     * Un hash que no es de contrasena (el marcador de los tokens antiguos,
     * por ejemplo) NUNCA coincide: no se compara nada.
     */
    public function coincide(string $token, ?string $hash): bool
    {
        if (! $this->esHashDeContrasena($hash)) {
            return false;
        }

        return password_verify($token, (string) $hash);
    }

    /**
     * ¿Este valor es un hash de contrasena?
     *
     * Solo se aceptan los formatos que `password_verify` entiende. Asi el
     * marcador de los tokens invalidados (SHA-256) no llega ni a compararse.
     */
    public function esHashDeContrasena(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return str_starts_with($hash, '$argon2id$')
            || str_starts_with($hash, '$argon2i$')
            || str_starts_with($hash, '$2y$');
    }

    /**
     * ¿El token es el ANTERIOR y sigue dentro de su ventana de gracia?
     *
     * La ventana de gracia existe para que la ROTACION no deje la caja muda:
     * el token anterior sigue valiendo hasta su fecha, y despues ya no.
     */
    public function dentroDeLaVentana(
        string $token,
        ?string $hashPrevio,
        ?CarbonInterface $vence,
    ): bool {
        if ($vence === null) {
            return false;
        }

        if (now()->greaterThan($vence)) {
            return false;
        }

        return $this->coincide($token, $hashPrevio);
    }

    /**
     * El ALGORITMO del hash: argon2id, o bcrypt si argon2id no esta.
     */
    private function algoritmo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
