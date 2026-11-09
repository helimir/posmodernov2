<?php

namespace Tests\Support;

/**
 * El ESCANER de literales de pais (design §E.3).
 *
 * Regla que hace ejecutable: *cambiar de pais es insertar filas, no bifurcar
 * codigo*. El motor fiscal, el de impuestos, el de numeracion y el de
 * formatos son DATO: un literal de pais en el dominio es un fork por pais.
 *
 * QUE ESCANEA: recursivamente `app/Domain/`. NO escanea `tests/`, `config/`,
 * `database/seeders/` ni `app/Providers/Fiscal/`: los adaptadores de canal,
 * los seeders y la configuracion contienen literales legitimos (son datos que
 * se siembran, no codigo que bifurca).
 *
 * QUE BUSCA: cinco categorias, y SOLO literales de cadena y comparaciones:
 *
 *  - `country-code`: codigos ISO-3166 alpha-2 EN POSICION DE COMPARACION. Se
 *    comparan ENTEROS: `'VE'` es un codigo, `'VENEZUELA'` no.
 *  - `country-name`: nombres de pais en literales.
 *  - `currency`: codigos y simbolos de moneda.
 *  - `authority`: autoridades y organismos (`seniat`, `sii`, `RIF`…).
 *  - `locale-token`: tokens normativos locales (`serie`, `(E)`).
 *
 * DONDE NO BUSCA: nombres de clase, metodo o constante. `VenezuelaFiscalProfile`
 * no es un literal: es un nombre. Y una palabra corriente que coincide con un
 * token no es un literal prohibido: `serie` (la palabra "serie") dentro de un
 * mensaje en castellano NO es el token del art. 20, que es el literal EXACTO
 * `'serie'`. Por eso el token `serie` se compara entero y los demas con
 * limites de palabra.
 */
final class CountryLiteralScanner
{
    /**
     * Los modos de comparacion de un token.
     *
     *  - `exacta`: el literal tiene que SER el token.
     *  - `palabra`: el token aparece como PALABRA, sin distinguir mayusculas.
     *  - `palabra-exacta`: el token aparece como PALABRA, respetando su caso.
     */
    private const EXACTA = 'exacta';

    private const PALABRA = 'palabra';

    private const PALABRA_EXACTA = 'palabra-exacta';

    /**
     * Las reglas del escaner: categoria => lista de tokens con su modo.
     *
     * @return array<string, list<array{token: string, modo: string}>>
     */
    public static function reglas(): array
    {
        return [
            // 1. Los codigos ISO-3166 alpha-2, en posicion de comparacion.
            'country-code' => array_map(
                static fn (string $codigo): array => ['token' => $codigo, 'modo' => self::EXACTA],
                ['VE', 'CL', 'MX', 'CO', 'PA', 'AR', 'PE', 'EC', 'BO', 'UY', 'GT', 'CR', 'PY', 'DO', 'PR', 'BR']
            ),

            // 2. Los nombres de pais, en literales.
            'country-name' => array_map(
                static fn (string $nombre): array => ['token' => $nombre, 'modo' => self::PALABRA],
                [
                    'Venezuela', 'Chile', 'Mexico', 'México', 'Colombia', 'Panama', 'Panamá',
                    'Argentina', 'Peru', 'Perú', 'Bolivia', 'Ecuador', 'Uruguay', 'Guatemala',
                    'Costa Rica', 'Paraguay', 'Dominicana', 'Brazil', 'Brasil',
                ]
            ),

            // 3. Los codigos y simbolos de moneda. Se comparan con su CASO
            //    real: en minusculas son palabras inglesas.
            'currency' => array_map(
                static fn (string $moneda): array => ['token' => $moneda, 'modo' => self::PALABRA_EXACTA],
                ['USD', 'VES', 'MXN', 'CLP', 'COP', 'PAB', 'Bs']
            ),

            // 4. Las autoridades y los organismos. Tambien con su caso real:
            //    `sat` en minusculas es el dia de la semana, `nit` es un
            //    piojo, y ninguno de los dos es una autoridad fiscal.
            'authority' => array_map(
                static fn (string $autoridad): array => ['token' => $autoridad, 'modo' => self::PALABRA_EXACTA],
                ['seniat', 'sii', 'sat', 'dian', 'dgi', 'RIF', 'RUC', 'NIT']
            ),

            // 5. Los tokens normativos locales. El token `serie` del art. 20
            //    es el LITERAL EXACTO: la palabra "serie" en castellano no es
            //    el token, y marcarla haria el escaner inservible.
            'locale-token' => [
                ['token' => 'serie', 'modo' => self::EXACTA],
                ['token' => 'Contribuyentes Formales del IVA', 'modo' => self::PALABRA],
                ['token' => '(E)', 'modo' => self::PALABRA_EXACTA],
            ],
        ];
    }

    /**
     * La raiz del proyecto. Se deduce del propio fichero y no de la
     * aplicacion: el escaner tiene que poder usarse tambien desde un test de
     * `tests/Unit`, donde Laravel no arranca.
     */
    public static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * El directorio que se escanea por defecto: el DOMINIO.
     */
    public static function directorioPorDefecto(): string
    {
        return self::raiz().'/app/Domain';
    }

    /**
     * Los HALLAZGOS de un directorio.
     *
     * @return list<array{ruta: string, linea: int, categoria: string, literal: string}>
     */
    public static function hallazgos(?string $directorio = null): array
    {
        $directorio = $directorio ?? self::directorioPorDefecto();
        $hallazgos = [];

        foreach (self::ficherosPhp($directorio) as $fichero) {
            $codigo = (string) file_get_contents($fichero);

            foreach (self::literales($codigo) as $literal) {
                foreach (self::reglas() as $categoria => $reglas) {
                    foreach ($reglas as $regla) {
                        if (! self::coincide($literal['valor'], $regla['token'], $regla['modo'])) {
                            continue;
                        }

                        $hallazgos[] = [
                            'ruta' => self::ruta($fichero),
                            'linea' => $literal['linea'],
                            'categoria' => $categoria,
                            'literal' => self::mostrar($literal['valor']),
                        ];

                        // Un literal se cuenta UNA vez: si encaja en dos
                        // categorias, se reporta la primera.
                        break 3;
                    }
                }
            }
        }

        return $hallazgos;
    }

    /**
     * El MENSAJE del fallo: la cabecera, una linea por hallazgo y el cierre de
     * la regla de oro (design §E.3).
     *
     * @param  list<array{ruta: string, linea: int, categoria: string, literal: string}>  $hallazgos
     */
    public static function mensajeDeFallo(array $hallazgos): string
    {
        $mensaje = 'Fallo de arquitectura: app/Domain/ contiene '.count($hallazgos).' literales de país.';

        foreach ($hallazgos as $hallazgo) {
            $mensaje .= "\n".$hallazgo['ruta'].':'.$hallazgo['linea']
                .'  categoría='.$hallazgo['categoria']
                .'  literal='.$hallazgo['literal'];
        }

        return $mensaje."\n".'El motor fiscal, de impuestos, de numeración y de formatos es DATO, '
            .'no código: cambiar de país debe consistir sólo en insertar filas.';
    }

    /**
     * Los ficheros PHP de un directorio, recursivamente.
     *
     * @return list<string>
     */
    private static function ficherosPhp(string $directorio): array
    {
        if (! is_dir($directorio)) {
            return [];
        }

        $ficheros = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS)
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
     * Los LITERALES de cadena de un codigo, con su linea.
     *
     * Se usa el tokenizador de PHP y no una expresion regular: el tokenizador
     * da la linea EXACTA y distingue el literal del comentario y del nombre.
     *
     * @return list<array{valor: string, linea: int}>
     */
    private static function literales(string $codigo): array
    {
        $literales = [];

        foreach (token_get_all($codigo) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            $valor = self::valorDe($token[1]);

            if ($valor === '') {
                continue;
            }

            $literales[] = ['valor' => $valor, 'linea' => $token[2]];
        }

        return $literales;
    }

    /**
     * El VALOR de un literal, sin sus comillas y con sus escapes resueltos.
     */
    private static function valorDe(string $literal): string
    {
        if (strlen($literal) < 2) {
            return $literal;
        }

        $contenido = substr($literal, 1, -1);

        // En PHP, dentro de '...' solo se escapan la barra y la comilla
        // simple; dentro de "..." hay mas escapes.
        if ($literal[0] === "'") {
            return (string) preg_replace('/\\\\(.)/', '$1', $contenido);
        }

        return stripcslashes($contenido);
    }

    /**
     * ¿Coincide el literal con el token?
     */
    private static function coincide(string $literal, string $token, string $modo): bool
    {
        if ($modo === self::EXACTA) {
            return $literal === $token;
        }

        // El limite de PALABRA: un codigo es un token, no una subcadena. Sin
        // ese limite, "copies" contendria "COP" y "absoluto" contendria "Bs".
        $patron = '/(?<![A-Za-z0-9])'.preg_quote($token, '/').'(?![A-Za-z0-9])/';

        if ($modo === self::PALABRA) {
            return preg_match($patron, $literal) === 1;
        }

        return preg_match($patron, $literal) === 1;
    }

    /**
     * El literal tal y como se muestra en el mensaje: en una linea y sin
     * partirla.
     */
    private static function mostrar(string $literal): string
    {
        $literal = trim((string) preg_replace('/\s+/u', ' ', $literal));

        if (mb_strlen($literal) > 60) {
            $literal = mb_substr($literal, 0, 60).'…';
        }

        return $literal;
    }

    /**
     * La ruta relativa a la raiz del proyecto, con separadores `/` para que
     * el mensaje sea el mismo en cualquier sistema.
     */
    private static function ruta(string $fichero): string
    {
        $raiz = str_replace('\\', '/', self::raiz()).'/';
        $relativa = str_replace('\\', '/', $fichero);

        return str_starts_with($relativa, $raiz) ? substr($relativa, strlen($raiz)) : $relativa;
    }
}
