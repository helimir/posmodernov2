<?php

namespace App\Print;

use InvalidArgumentException;
use Mike42\Escpos\CapabilityProfile;
use RuntimeException;

/**
 * El CATALOGO de perfiles de impresora, y las codepaginas de cada uno.
 *
 * REGLA que fija esta clase: *el perfil de impresora es DATO, no codigo*
 * (design D5, ADR-0005). Los perfiles son los de la libreria de impresion, y
 * el destino declara cual usa. El servidor consulta las capacidades REALES
 * del perfil antes de componer nada: si el destino no soporta lo que el
 * ticket pide, el fallo ocurre en el servidor, antes de enviar nada.
 *
 * Y LA CODIGEPAGINA ES UN DATO DEL DESTINO: la tabla de la codepagina la
 * declara el perfil (`getCodePages()`), asi que no hay una tabla fija en el
 * codigo. Y una codepagina que la libreria no sabe codificar NO se imprime:
 * el texto saldria con signos de interrogacion, y un ticket con signos de
 * interrogacion es un ticket mudo.
 */
final readonly class PrintProfiles
{
    /**
     * La codepagina por defecto. CP850 y no CP858 porque CP858 NO es
     * codificable en la libreria: la tabla 19 se declara, pero sin tabla de
     * conversion, y el texto saldria con signos de interrogacion.
     */
    public const PREDETERMINADA = 'CP850';

    /**
     * Los PERFILES de la libreria, con sus codepaginas codificables.
     *
     * Es la lista que la pantalla ofrece: el perfil es un dato que la tienda
     * declara, y la lista sale de la libreria, no de una lista escrita a mano
     * en la aplicacion.
     *
     * @return list<array{id: string, name: string, code_pages: list<string>}>
     */
    public static function todos(): array
    {
        $perfiles = [];

        foreach (CapabilityProfile::getProfileNames() as $nombre) {
            $perfil = CapabilityProfile::load($nombre);

            $perfiles[] = [
                'id' => (string) $perfil->getId(),
                'name' => (string) $perfil->getName(),
                'code_pages' => self::codepaginasCodificables($perfil),
            ];
        }

        return $perfiles;
    }

    /**
     * El PERFIL de la libreria, o un error con mensaje util.
     *
     * @throws RuntimeException Si el perfil no existe en la libreria.
     */
    public static function carga(string $nombre): CapabilityProfile
    {
        try {
            return CapabilityProfile::load($nombre);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException(
                "El perfil de impresora «{$nombre}» no existe en la libreria de impresion: "
                .'los perfiles admitidos son: '.implode(', ', CapabilityProfile::getProfileNames()).'.',
                previous: $error
            );
        }
    }

    /**
     * La TABLA de la codepagina pedida, en el perfil dado.
     *
     * La codepagina es un DATO del destino y la tabla es un dato del perfil:
     * la libreria relaciona ambas (`getCodePages()`), asi que no hay una
     * tabla fija en el codigo.
     *
     * @throws RuntimeException Si el perfil no declara la codepagina, o si la
     *                          libreria no la sabe codificar.
     */
    public static function tablaDeCodepagina(CapabilityProfile $perfil, string $codepagina): int
    {
        $codepagina = strtoupper(trim($codepagina));

        if ($codepagina === '') {
            $codepagina = self::PREDETERMINADA;
        }

        foreach ($perfil->getCodePages() as $tabla => $codePage) {
            if (strtoupper($codePage->getId()) !== $codepagina) {
                continue;
            }

            if (! $codePage->isEncodable()) {
                throw new RuntimeException(
                    "La codepagina {$codepagina} no es codificable en la libreria de impresion: "
                    .'usa '.self::PREDETERMINADA.' en su lugar.'
                );
            }

            return (int) $tabla;
        }

        throw new RuntimeException(
            "El perfil de impresora «{$perfil->getId()}» no declara la codepagina {$codepagina}."
        );
    }

    /**
     * Las CODEPAGINAS que el perfil sabe codificar.
     *
     * Son las que la pantalla ofrece: ofrecer una codepagina que la libreria
     * no sabe codificar es ofrecer un ticket mudo.
     *
     * @return list<string>
     */
    public static function codepaginasCodificables(CapabilityProfile $perfil): array
    {
        $codepaginas = [];

        foreach ($perfil->getCodePages() as $codePage) {
            if (! $codePage->isEncodable()) {
                continue;
            }

            $codepaginas[] = strtoupper((string) $codePage->getId());
        }

        sort($codepaginas);

        return array_values(array_unique($codepaginas));
    }
}
