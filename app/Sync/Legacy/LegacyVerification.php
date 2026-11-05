<?php

namespace App\Sync\Legacy;

/**
 * El RESULTADO de verificar una peticion del sync legacy.
 *
 * REGLA que fija esta clase: el MOTIVO INTERNO nunca sale al cliente.
 *
 * `reason` es la informacion del SERVIDOR (por que se rechazo, o la
 * anotacion de un reenvio) y vive en el log. Lo que ve el terminal es
 * siempre la respuesta del legacy: un 401 con `Invalid Action` y
 * `for: invalid`, opaco.
 *
 * Los motivos posibles son los del legacy (`_inc/helper/sync.php`):
 * `unknown_client`, `bad_timestamp`, `expired_request`, `bad_signature`,
 * `replayed_request`, `signature_required`, `legacy_auth_not_configured` y
 * `bad_credentials`. Mas uno de esta implementacion:
 * `nonce_store_unavailable`, que es el fallo CERRADO cuando Redis no esta
 * (sin almacen de nonces no hay anti-replay, y sin anti-replay no se
 * acepta nada).
 */
final readonly class LegacyVerification
{
    /**
     * @param  bool  $ok  Si la peticion quedo autenticada.
     * @param  string|null  $reason  El motivo interno: solo para el log del servidor.
     * @param  string|null  $client  El cliente autenticado (el `username` del protocolo).
     * @param  string|null  $mode  `signed`, `legacy` o `legacy-previous`.
     * @param  int  $httpStatus  200 si se acepta, 401 si se rechaza.
     * @param  string  $message  El mensaje de la respuesta: el del legacy.
     * @param  string  $for  `sync` si se acepta, `invalid` si se rechaza.
     */
    public function __construct(
        public bool $ok,
        public ?string $reason,
        public ?string $client,
        public ?string $mode,
        public int $httpStatus,
        public string $message,
        public string $for,
    ) {}

    /**
     * La peticion esta autenticada.
     *
     * El `reason` es la anotacion interna del caso (por ejemplo
     * `already_applied` cuando el lote ya se habia aplicado): la respuesta
     * del terminal es la misma de siempre.
     */
    public static function aceptado(string $client, string $mode, ?string $reason = null): self
    {
        return new self(
            ok: true,
            reason: $reason,
            client: $client,
            mode: $mode,
            httpStatus: 200,
            message: 'sync successfully done',
            for: 'sync',
        );
    }

    /**
     * La peticion se rechaza.
     *
     * La respuesta es la del legacy, opaca: el motivo va al log del
     * servidor y el terminal solo ve `Invalid Action`.
     */
    public static function rechazado(string $reason): self
    {
        return new self(
            ok: false,
            reason: $reason,
            client: null,
            mode: null,
            httpStatus: 401,
            message: 'Invalid Action',
            for: 'invalid',
        );
    }
}
