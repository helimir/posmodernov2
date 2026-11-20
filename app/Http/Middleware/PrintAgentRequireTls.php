<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El TLS del canal del agente LAN.
 *
 * REGLA que fija este middleware: *el token del agente NO viaja en una
 * peticion sin TLS*.
 *
 * El token va en la cabecera `Authorization`, y el trabajo que el agente
 * imprime lleva DATOS FISCALES (el comprobante y su QR). En una LAN sin
 * cifrar, cualquiera que espie el trafico tiene lo que el agente tiene: la
 * credencial de la caja y los tickets que imprime.
 *
 * Y VA ANTES QUE LA AUTENTICACION: si la peticion no es segura, se rechaza
 * SIN mirar el token. Mirarlo ya seria dejarlo viajar en claro.
 *
 * El interruptor es `PRINT_REQUIRE_TLS` (`config/print.php`), y esta ENCENDIDO
 * por defecto. En `local` y en `testing` no se exige: lo dice el ENTORNO, y
 * ahi no hay TLS.
 */
final class PrintAgentRequireTls
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->exigeTls() && ! $this->esSegura($request)) {
            return $this->prohibido(
                'El canal del agente exige TLS: el token viaja en la cabecera Authorization '
                .'y el trabajo lleva datos fiscales. Publica el endpoint del agente por HTTPS; '
                .'si el TLS termina en un proxy, activa PRINT_TRUST_FORWARDED_PROTO=true. '
                .'Solo en una LAN que no sale de la tienda puedes bajar PRINT_REQUIRE_TLS=false.'
            );
        }

        return $next($request);
    }

    /**
     * ¿El TLS se exige aqui?
     */
    private function exigeTls(): bool
    {
        if (! filter_var(config('print.require_tls'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        // El desarrollo y las PRUEBAS no usan TLS: lo dice el ENTORNO.
        $exentos = (array) config('print.tls_exempt_environments', []);

        return ! in_array(app()->environment(), $exentos, true);
    }

    /**
     * ¿La peticion ha llegado segura?
     *
     * `isSecure()` ya respeta los proxies de confianza de Laravel. La
     * cabecera `X-Forwarded-Proto` SOLO se mira cuando el operador dice que
     * hay un proxy de confianza: si no, cualquiera podria fingir que su
     * peticion en claro es HTTPS.
     */
    private function esSegura(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        if (! filter_var(config('print.trust_forwarded_proto'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return strtolower(trim((string) $request->header('X-Forwarded-Proto', ''))) === 'https';
    }

    private function prohibido(string $mensaje): JsonResponse
    {
        return response()->json(['errorMsg' => $mensaje], 403);
    }
}
