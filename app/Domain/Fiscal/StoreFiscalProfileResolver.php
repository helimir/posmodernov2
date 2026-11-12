<?php

namespace App\Domain\Fiscal;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El PERFIL FISCAL DE UNA TIENDA.
 *
 * REGLA que fija esta clase: *la tienda resuelve su perfil fiscal por su
 * PAIS, con vigencia, y si declara un perfil propio, ese manda*.
 *
 * El orden de la precedencia es este, y cada eslabon dice cual es el
 * siguiente:
 *
 *  1. EL PERFIL DECLARADO POR LA TIENDA (`stores.fiscal_profile_id`): si la
 *     tienda lo declara, ese manda. Es el caso del regimen especial: una
 *     tienda que opera bajo un regimen que NO es el de su pais lo dice, y su
 *     decirlo es un dato, no una excepcion.
 *  2. EL PERFIL VIGENTE DE SU PAIS (`fiscal_profiles.country_id`): el del
 *     pais de la tienda cuyo `effective_from` ya ha llegado y cuyo
 *     `effective_to` no ha pasado. Si hay VARIOS vigentes (distintos
 *     regimenes o versiones), manda el de vigencia MAS RECIENTE: el
 *     `effective_from` mas nuevo y, a igualdad, el identificador mas alto.
 *  3. NADA: si la tienda no declara pais, no hay nada que saber, y el
 *     resolvedor devuelve `null` para que el que pregunta decida.
 *
 * LO QUE NO HACE, Y POR QUE:
 *
 *  - NO CONOCE NI EL PAIS NI EL REGIMEN: eso es DATO de la base de datos.
 *    Aqui no hay ni un literal de pais, y el perfil se busca por la RELACION
 *    (la columna `country_id`), no por su nombre.
 *  - NO CAE A LA "PRIMERA SERIE POR ROL" A CIEGAS: una serie de otro pais
 *    es una serie que no es de esta tienda. Cuando la tienda declara pais y
 *    no hay perfil vigente, el fallo es UTIL (que tienda, que pais y que no
 *    hay perfil vigente), porque un fallo asi se arregla; una serie ajena no
 *    se ve.
 *  - NO ESCRIBE NADA: solo resuelve.
 *
 * LA VIGENCIA ES INCLUSIVA por los dos lados: `effective_from` y
 * `effective_to` son el PRIMER y el ULTIMO dia del regimen. Un `NULL` en
 * `effective_from` significa "en vigor desde siempre", y un `NULL` en
 * `effective_to` significa "sin fecha de fin": los dos son regimenes que
 * siguen en vigor.
 */
final readonly class StoreFiscalProfileResolver
{
    public function __construct(private Connection $connection) {}

    /**
     * El PERFIL FISCAL de una tienda.
     *
     * @param  int  $storeId  La tienda cuyo perfil se resuelve.
     * @param  string  $fecha  La fecha (`Y-m-d`) con la que se mide la
     *                         vigencia: la del dia de la tienda.
     * @return int|null El perfil de la tienda, o `null` cuando la tienda no
     *                  declara ni perfil ni pais (y entonces no hay nada mas
     *                  que saber).
     *
     * @throws RuntimeException Cuando la tienda declara un perfil que no
     *                          existe, cuando declara un perfil de OTRO
     *                          pais, o cuando su pais no tiene ningun
     *                          perfil vigente.
     */
    public function resolve(int $storeId, string $fecha): ?int
    {
        $tienda = $this->connection->table('stores')->where('id', $storeId)->first();

        if ($tienda === null) {
            throw new RuntimeException(
                "La tienda {$storeId} no existe: no se puede resolver su perfil fiscal."
            );
        }

        $countryId = $tienda->country_id === null ? null : (int) $tienda->country_id;
        $declaradoId = $tienda->fiscal_profile_id === null ? null : (int) $tienda->fiscal_profile_id;

        // 1. EL PERFIL DECLARADO: si la tienda lo declara, ese manda.
        if ($declaradoId !== null) {
            return $this->perfilDeclarado($storeId, $declaradoId, $countryId);
        }

        // 2. EL PERFIL DEL PAIS, CON VIGENCIA.
        if ($countryId === null) {
            // 3. NADA: la tienda no declara pais, y sin pais no hay forma de
            //    saber mas.
            return null;
        }

        $vigente = $this->vigenteDePais($countryId, $fecha);

        if ($vigente === null) {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene perfil fiscal vigente: el pais {$countryId} "
                ."no declara ninguno en vigor el {$fecha}. Sin perfil no hay serie de documento, "
                .'y una serie de otro pais no es una serie: corrige el regimen fiscal de la tienda.'
            );
        }

        return $vigente;
    }

    /**
     * El PERFIL VIGENTE de un pais en una fecha.
     *
     * Devuelve `null` cuando el pais no tiene ninguno en vigor: que no
     * tenga ninguno es un dato, y el que pregunta decide que hacer con el.
     *
     * @param  int  $countryId  El pais.
     * @param  string  $fecha  La fecha (`Y-m-d`) de la vigencia.
     */
    public function vigenteDePais(int $countryId, string $fecha): ?int
    {
        $id = $this->connection->table('fiscal_profiles')
            ->where('country_id', $countryId)
            // El regimen ya ha entrado en vigor.
            ->where(function ($query) use ($fecha): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $fecha);
            })
            // Y todavia no ha salido de vigor.
            ->where(function ($query) use ($fecha): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $fecha);
            })
            // El desempate DETERMINISTA: el mas reciente, y a igualdad el de
            // identificador mas alto. Sin el, dos cajas podrian resolver
            // perfiles distintos el mismo dia.
            ->orderByRaw('effective_from DESC NULLS LAST')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * El PERFIL DECLARADO por la tienda, comprobado.
     *
     * Un perfil de OTRO pais es un error de DATOS, no un fallo silencioso:
     * emitir con el regimen de otro pais es peor que no emitir, y el
     * mensaje dice que tienda es, que perfil declara y de que pais es cada
     * cosa.
     *
     * @param  int  $storeId  La tienda que declara el perfil.
     * @param  int  $profileId  El perfil declarado.
     * @param  int|null  $countryId  El pais de la tienda, si lo declara.
     */
    private function perfilDeclarado(int $storeId, int $profileId, ?int $countryId): int
    {
        $perfil = $this->connection->table('fiscal_profiles')
            ->where('id', $profileId)
            ->first();

        if ($perfil === null) {
            throw new RuntimeException(
                "La tienda {$storeId} declara el perfil fiscal {$profileId}, que no existe."
            );
        }

        $paisDelPerfil = (int) $perfil->country_id;

        if ($countryId !== null && $paisDelPerfil !== $countryId) {
            throw new RuntimeException(
                "La tienda {$storeId} declara el perfil fiscal {$profileId}, que es del pais "
                ."{$paisDelPerfil}, y la tienda es del pais {$countryId}: un perfil de otro pais es "
                .'un error de datos, no un fallo silencioso. Corrige el perfil declarado de la tienda o su pais.'
            );
        }

        return $profileId;
    }
}
