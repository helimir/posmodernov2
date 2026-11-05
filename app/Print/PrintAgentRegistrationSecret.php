<?php

namespace App\Print;

/**
 * El SECRETO DE REGISTRO del agente LAN, por tienda.
 *
 * Regla que fija: *el registro de un agente exige el secreto de SU tienda, y
 * de ese secreto en la base de datos solo queda el HASH*.
 *
 * El registro (`POST /api/print-agents/register`) es el unico endpoint
 * PUBLICO de la API del agente LAN: no hay token todavia, porque el token es
 * lo que el registro entrega. Por eso necesita una segunda defensa: el
 * secreto de la tienda, que solo conoce quien instala el agente.
 *
 * La REGLA vive aqui y en ningun otro sitio, para que el comando que EMITE
 * el secreto y el endpoint que lo EXIGEN no puedan separarse: el secreto se
 * genera con 256 bits de entropia, se compara en TIEMPO CONSTANTE sobre su
 * SHA-256, y el secreto en claro nunca se escribe.
 *
 * Esta clase es PURO: no toca la base de datos. Quien escribe, es quien
 * decide el destino del hash.
 */
final class PrintAgentRegistrationSecret
{
    /**
     * Los bytes de entropia del secreto: 256 bits.
     */
    private const BYTES = 32;

    /**
     * Un secreto NUEVO, en claro, con 256 bits de entropia.
     *
     * Es base64url y NO hexadecimal a proposito: su forma es DISTINTA de la
     * del hash (64 caracteres hexadecimales), asi que un secreto en claro
     * jamas puede confundirse con un digest ni caber en la columna que lo
     * guarda. Ademas, base64url son caracteres validos en una cabecera HTTP.
     */
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    /**
     * El HASH del secreto: lo unico que se guarda.
     */
    public function hash(string $secreto): string
    {
        return hash('sha256', $secreto);
    }

    /**
     * ¿Coincide el secreto recibido con el hash guardado?
     *
     * La comparacion es en TIEMPO CONSTANTE (`hash_equals`): se compara el
     * hash del secreto recibido con el hash guardado, nunca el secreto en
     * claro con el hash.
     */
    public function coincide(string $secreto, string $hash): bool
    {
        return hash_equals($hash, $this->hash($secreto));
    }
}
