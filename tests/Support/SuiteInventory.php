<?php

namespace Tests\Support;

/**
 * El INVENTARIO de la suite: que tests existen, con que nombre, en que
 * fichero y con que documentacion.
 *
 * Lo usan los tests que miran la suite desde fuera:
 *
 *  - el barrido de los nombres canonicos (`tests/Architecture/
 *    CanonicalTestNamesTest.php`), que exige que cada nombre exista
 *    EXACTAMENTE una vez;
 *  - los meta-tests (`tests/Architecture/SuiteCoverageTest.php` y
 *    `SuiteBaselineTest.php`), que comprueban que las areas del dominio estan
 *    cubiertas por tests que no estan vacios.
 *
 * El inventario se lee del CODIGO con el TOKENIZADOR de PHP, no con una
 * expresion regular: el tokenizador sabe donde acaba el cuerpo de un test
 * (una llamada `test(...)` con su cierre) y donde esta su documentacion, sin
 * confundirse con una llamada parecida dentro del cuerpo.
 */
final class SuiteInventory
{
    /**
     * La raiz de los tests.
     */
    public static function raiz(): string
    {
        return self::raizDelProyecto().'/tests';
    }

    /**
     * La raiz del PROYECTO. Se deduce del propio fichero y no de la
     * aplicacion: el inventario tiene que poder usarse tambien desde un test
     * de `tests/Unit`, donde Laravel no arranca.
     */
    public static function raizDelProyecto(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Todos los tests declarados en la suite.
     *
     * @return list<array{
     *     nombre: string,
     *     fichero: string,
     *     linea: int,
     *     docblock: string,
     *     cuerpo: string
     * }>
     */
    public static function tests(): array
    {
        $tests = [];

        foreach (self::ficheros() as $fichero) {
            $codigo = (string) file_get_contents($fichero);
            $tokens = token_get_all($codigo);
            $total = count($tokens);

            for ($indice = 0; $indice < $total; $indice++) {
                $token = $tokens[$indice];

                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                // Las dos formas de declarar un test en Pest: `test(...)` e
                // `it(...)`.
                if ($token[1] !== 'test' && $token[1] !== 'it') {
                    continue;
                }

                // La llamada `test(...)`: ni un metodo `->test()` ni un
                // `Test::test()`.
                $anterior = self::anteriorSignificativo($tokens, $indice);

                if ($anterior !== null && in_array($tokens[$anterior], ['->', '::', '?->'], true)) {
                    continue;
                }

                $abre = self::siguienteSignificativo($tokens, $indice);

                if ($abre === null || $tokens[$abre] !== '(') {
                    continue;
                }

                $cierra = self::pareja($tokens, $abre, '(', ')');

                if ($cierra === null) {
                    continue;
                }

                $nombre = self::nombreDe($tokens, $abre, $cierra);

                if ($nombre === null) {
                    continue;
                }

                // El CUERPO del test: la llave de la funcion anonima que se
                // pasa como segundo argumento. Va DENTRO de la llamada
                // `test(...)`, asi que se busca despues del `(`.
                $llave = null;

                for ($j = $abre + 1; $j < $total; $j++) {
                    if ($tokens[$j] === '{') {
                        $llave = $j;
                        break;
                    }
                }

                $cuerpo = '';

                if ($llave !== null) {
                    $fin = self::pareja($tokens, $llave, '{', '}');

                    if ($fin !== null) {
                        for ($j = $llave; $j <= $fin; $j++) {
                            $cuerpo .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                        }
                    }
                }

                $tests[] = [
                    'nombre' => $nombre,
                    'fichero' => self::ruta($fichero),
                    'linea' => $token[2],
                    'docblock' => self::docblockDe($tokens, $indice),
                    'cuerpo' => $cuerpo,
                ];
            }
        }

        return $tests;
    }

    /**
     * Los ficheros PHP de la suite, en orden.
     *
     * @return list<string>
     */
    public static function ficheros(): array
    {
        $raiz = self::raiz();

        if (! is_dir($raiz)) {
            return [];
        }

        $ficheros = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $fichero) {
            if (! $fichero instanceof \SplFileInfo || ! $fichero->isFile()) {
                continue;
            }

            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $ficheros[] = $fichero->getPathname();
        }

        sort($ficheros);

        return $ficheros;
    }

    /**
     * Las apariciones de cada nombre declarado en la suite.
     *
     * @return array<string, list<string>> Nombre => lista de `ruta:linea`.
     */
    public static function apariciones(): array
    {
        $apariciones = [];

        foreach (self::tests() as $test) {
            $apariciones[$test['nombre']][] = $test['fichero'].':'.$test['linea'];
        }

        return $apariciones;
    }

    /**
     * El nombre del test: el primer literal de cadena de la llamada.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function nombreDe(array $tokens, int $abre, int $cierra): ?string
    {
        for ($i = $abre + 1; $i < $cierra; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                return trim($tokens[$i][1], "'\"");
            }
        }

        return null;
    }

    /**
     * El DOCBLOCK que precede a la declaracion, si lo hay.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function docblockDe(array $tokens, int $indice): string
    {
        for ($i = $indice - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_DOC_COMMENT) {
                return $token[1];
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            return '';
        }

        return '';
    }

    /**
     * El indice del token siguiente que NO es espacio ni comentario.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function siguienteSignificativo(array $tokens, int $indice): ?int
    {
        $total = count($tokens);

        for ($i = $indice + 1; $i < $total; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * El indice del token anterior que NO es espacio ni comentario.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function anteriorSignificativo(array $tokens, int $indice): ?int
    {
        for ($i = $indice - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * El indice del token que CIERRA al que esta en `$indice`.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function pareja(array $tokens, int $indice, string $abre, string $cierra): ?int
    {
        $total = count($tokens);
        $profundidad = 0;
        // Las interpolaciones ("{$variable}") abren y cierran llaves que NO
        // son llaves del codigo: su cierre es una llave de verdad y hay que
        // distinguirla.
        $interpolaciones = 0;

        for ($i = $indice; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_CURLY_OPEN) {
                    $interpolaciones++;
                }

                continue;
            }

            // El cierre de una interpolacion ("{$variable}") es una llave de
            // verdad, pero NO es una llave del codigo: se salta siempre, sea
            // la que sea la pareja que se busca.
            if ($token === '}' && $interpolaciones > 0) {
                $interpolaciones--;

                continue;
            }

            if ($token === $abre) {
                $profundidad++;

                continue;
            }

            if ($token === $cierra) {
                $profundidad--;

                if ($profundidad === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * La ruta relativa a la raiz del proyecto, con separadores `/` para que
     * el mensaje sea el mismo en cualquier sistema.
     */
    private static function ruta(string $fichero): string
    {
        $raiz = str_replace('\\', '/', self::raizDelProyecto()).'/';
        $relativa = str_replace('\\', '/', $fichero);

        return str_starts_with($relativa, $raiz) ? substr($relativa, strlen($raiz)) : $relativa;
    }
}
