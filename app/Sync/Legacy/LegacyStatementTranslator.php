<?php

namespace App\Sync\Legacy;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El TRADUCTOR del SQL crudo del terminal legacy.
 *
 * REGLA que fija esta clase: *un lote es TODO o NADA*.
 *
 * El terminal legacy empuja SQL crudo de MySQL con los nombres de SU
 * esquema (`selling_info`, `selling_item`, ...). El maestro es PostgreSQL
 * con otro esquema, asi que los IDENTIFICADORES se traducen contra el mapa
 * SEMBRADO (`legacy_translation_map`), nunca contra codigo: un `if
 * ($tabla === 'selling_info')` aqui seria un defecto (ADR-0006).
 *
 * La direccion es LEGACY -> DESTINO. El traductor devuelve operaciones
 * ESTRUCTURADAS, nunca SQL: el que escribe en la base de datos es otra
 * unidad (el aplicador del lote), y asi el mapa se puede auditar sin tocar
 * el codigo.
 *
 * Hay DOS puertas publicas: `translate()` traduce el LOTE entero y
 * `translateOne()` traduce UNA operacion. El aplicador usa la segunda, porque
 * un lote se aplica operacion a operacion: un `resolve_ref` a algo creado
 * por una operacion ANTERIOR del mismo lote tiene que resolver, y solo
 * resuelve si la traduccion ocurre DESPUES de que esa operacion anterior se
 * aplico.
 *
 * Las reglas que fija esta clase son SIETE:
 *
 *   1. Solo `INSERT`, `UPDATE` y `DELETE`. Cualquier otra sentencia
 *      rechaza el lote entero.
 *   2. Los identificadores se resuelven contra el mapa sembrado. Una
 *      TABLA sin fila de tabla (`legacy_column = '*'`) rechaza el lote
 *      entero. Una COLUMNA sin fila se descarta (su valor no viaja), pero
 *      si la sentencia la USA PARA FILTRAR, el lote entero se rechaza: no
 *      se puede filtrar por algo que no existe.
 *   3. `resolve_ref`: el legacy manda un identificador de TEXTO y el
 *      destino tiene una FK `bigint`. Se busca el id real en la tabla que
 *      declara la fila de referencia. Si no se encuentra, el lote entero
 *      se rechaza: un id inventado seria una venta que no existe.
 *   4. `cast_enum`: los pares `legacy:destino` van en el `transform`. Un
 *      valor del legacy que no este en los pares rechaza el lote entero.
 *   5. `inject_store`, `inject_reference` e `inject_business_date`: el
 *      valor sale del CONTEXTO del lote. Si el contexto no lo trae, el
 *      lote entero se rechaza.
 *   6. El des-escapeo ocurre UNA VEZ, en la frontera: el legacy
 *      HTML-escapea sus entradas, asi que el maestro recibe `&amp;` donde
 *      deberia haber `&`.
 *   7. Aqui no hay ni una constante con un nombre de tabla o de columna
 *      del legacy: el unico sitio donde viven es el mapa sembrado.
 *
 * Lo que NO se traduce (y por lo tanto RECHAZA el lote entero) es todo lo
 * que el legacy escribe y aqui no se sabe decir:
 *
 *   - una EXPRESION en el `SET` (`col = col - ?`): el destino no puede
 *     calcularla igual y el master tendria que inventarse el saldo,
 *   - un `LIMIT`: MySQL lo usa y PostgreSQL no lo soporta,
 *   - un `;` apilado: son dos sentencias en una operacion,
 *   - un `SELECT`: el log del legacy solo lleva escrituras,
 *   - un `OR` en el `WHERE`: el destino filtra por columnas, y una
 *     disjuncion no es una conjuncion.
 *
 * Los importes viajan como TEXTO decimal, nunca como `float`: un flotante
 * no es dinero, y el motor de PostgreSQL no lo volveria a leer igual.
 */
final readonly class LegacyStatementTranslator
{
    /**
     * La MARCA DE DESCARTE: el `type` de una operacion cuya tabla del legacy
     * NO se traslada.
     *
     * No es un rejection: es una operacion que NO produce nada aqui, porque
     * su destino no existe en este esquema (el mapa lo dice con
     * `new_table IS NULL`). Descartarla deja al terminal CONGELADO, que es
     * justo el fallo que el ADR-0006 existe para tapar.
     */
    public const DESCARTE = 'discard';

    /**
     * Un IDENTIFICADOR: con backticks (como los escribe el legacy) o sin
     * ellos.
     */
    private const IDENTIFICADOR = '(?:`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)';

    /**
     * Una LISTA de identificadores separada por comas.
     */
    private const LISTA_IDENTIFICADORES = self::IDENTIFICADOR.'(?:\s*,\s*'.self::IDENTIFICADOR.')*';

    /**
     * Un LITERAL simple: un numero o una cadena entre comillas.
     */
    private const LITERAL = '(?:\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|-?\d+(?:\.\d+)?)';

    /**
     * Un VALOR: un placeholder `?` o un literal simple.
     */
    private const VALOR = '(?:\?|'.self::LITERAL.')';

    /**
     * Una ASIGNACION: `columna = valor`.
     */
    private const ASIGNACION = self::IDENTIFICADOR.'\s*=\s*'.self::VALOR;

    /**
     * Una LISTA de asignaciones separada por comas.
     */
    private const LISTA_ASIGNACIONES = self::ASIGNACION.'(?:\s*,\s*'.self::ASIGNACION.')*';

    /**
     * Una CONDICION: `columna = valor`.
     */
    private const CONDICION = self::IDENTIFICADOR.'\s*=\s*'.self::VALOR;

    /**
     * Una LISTA de condiciones unidas por `AND` u `OR`.
     */
    private const LISTA_CONDICIONES = self::CONDICION.'(?:\s+(?:AND|OR)\s+'.self::CONDICION.')*';

    /**
     * La sentencia `INSERT`.
     */
    private const RE_INSERT = '/^INSERT\s+INTO\s+(?<tabla>'.self::IDENTIFICADOR.')\s*\(\s*(?<columnas>'.self::LISTA_IDENTIFICADORES.')\s*\)\s*VALUES\s*\(\s*(?<valores>(?:\?\s*,\s*)*\?)\s*\)$/iD';

    /**
     * La sentencia `UPDATE`.
     */
    private const RE_UPDATE = '/^UPDATE\s+(?<tabla>'.self::IDENTIFICADOR.')\s+SET\s+(?<asignaciones>'.self::LISTA_ASIGNACIONES.')\s+WHERE\s+(?<condiciones>'.self::LISTA_CONDICIONES.')$/iD';

    /**
     * La sentencia `DELETE`.
     */
    private const RE_DELETE = '/^DELETE\s+FROM\s+(?<tabla>'.self::IDENTIFICADOR.')\s+WHERE\s+(?<condiciones>'.self::LISTA_CONDICIONES.')$/iD';

    /**
     * Una ASIGNACION suelta, para extraerla de la lista.
     */
    private const RE_ASIGNACION = '/(?<columna>'.self::IDENTIFICADOR.')\s*=\s*(?<valor>'.self::VALOR.')/i';

    /**
     * Una CONDICION suelta, para extraerla de la lista.
     */
    private const RE_CONDICION = '/^(?<columna>'.self::IDENTIFICADOR.')\s*=\s*(?<valor>'.self::VALOR.')$/i';

    /**
     * La clave primaria del DESTINO. No es un nombre del legacy: es la
     * convencion del esquema de aqui (`$table->id()`).
     */
    private const CLAVE_PRIMARIA = 'id';

    /**
     * La escala de los importes del destino (`decimal(25,4)`).
     */
    private const ESCALA_DECIMAL = 4;

    public function __construct(private Connection $connection) {}

    /**
     * Traduce el LOTE del terminal legacy.
     *
     * @param  array<int, array<string, mixed>>  $operations  El `data` del lote, ya decodificado: una lista de `{sql, args}`.
     * @param  array<string, mixed>  $context  El contexto del lote: `store_id`, `sale_id`, `business_date` y `sequence`.
     * @return array<int, array<string, mixed>> Las operaciones ESTRUCTURADAS del destino.
     *
     * @throws RuntimeException Cuando UNA sola operacion no se puede traducir. En ese caso no se devuelve NADA.
     */
    public function translate(array $operations, array $context = []): array
    {
        $traducidas = [];

        foreach ($operations as $indice => $operacion) {
            if (! is_array($operacion)) {
                throw new RuntimeException("La operacion {$indice} del lote no es una operacion.");
            }

            try {
                $traducida = $this->translateOne($operacion, $context);
            } catch (RuntimeException $e) {
                throw new RuntimeException("La operacion {$indice} del lote: ".$e->getMessage(), 0, $e);
            }

            // Una tabla que el mapa declara NO trasladable NO produce
            // operacion: su destino no existe aqui.
            if (($traducida['type'] ?? null) !== self::DESCARTE) {
                $traducidas[] = $traducida;
            }
        }

        return $traducidas;
    }

    /**
     * Traduce UNA operacion del lote.
     *
     * Es la puerta que usa el APLICADOR (`LegacyBatchApplier`), que aplica el
     * lote operacion a operacion: un `resolve_ref` a algo creado por una
     * operacion ANTERIOR del mismo lote tiene que resolver, y solo resuelve
     * si la traduccion ocurre DESPUES de que esa operacion anterior se
     * aplico. El traductor busca contra el estado ACTUAL de la base, y
     * dentro de la transaccion del lote ese estado ya incluye lo aplicado
     * antes. Esta es la TENSION que el traductor dejo abierta y que el
     * aplicador cierra.
     *
     * @param  array<string, mixed>  $operation  La operacion del lote: `{sql, args}`.
     * @param  array<string, mixed>  $context  El contexto del lote: `store_id`, `sale_id`, `business_date` y `sequence`.
     * @return array<string, mixed> La operacion ESTRUCTURADA, o la MARCA DE
     *                              DESCARTE (`type` = `discard`) cuando la
     *                              tabla del legacy NO se traslada.
     *
     * @throws RuntimeException Cuando la operacion no se puede traducir.
     */
    public function translateOne(array $operation, array $context = []): array
    {
        // El MAPA se relee en cada operacion: son dieciocho tablas y el
        // lote esta acotado por `SYNC_MAX_STATEMENTS`. Releerlo es lo que
        // hace que el lote entero vea el mapa que hay en este momento, y no
        // el que habia cuando empezo.
        $mapa = $this->cargarMapa();

        return $this->traducirOperacion($operation, $context, $mapa);
    }

    /**
     * El MAPA sembrado, cargado UNA vez por operacion.
     *
     * @return array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>
     */
    private function cargarMapa(): array
    {
        $filas = $this->connection->table('legacy_translation_map')
            ->get(['legacy_table', 'legacy_column', 'new_table', 'new_column', 'transform']);

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(string) $fila->legacy_table][(string) $fila->legacy_column] = [
                'new_table' => $fila->new_table === null ? null : (string) $fila->new_table,
                'new_column' => $fila->new_column === null ? null : (string) $fila->new_column,
                'transform' => $fila->transform === null ? null : (string) $fila->transform,
            ];
        }

        return $mapa;
    }

    /**
     * Traduce UNA operacion del lote.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array<string, mixed> La operacion ESTRUCTURADA, o la MARCA DE DESCARTE.
     */
    private function traducirOperacion(mixed $operacion, array $contexto, array $mapa): array
    {
        if (! is_array($operacion) || ! isset($operacion['sql']) || ! is_string($operacion['sql'])) {
            throw new RuntimeException('La operacion del lote no trae una sentencia `sql` valida.');
        }

        $args = $operacion['args'] ?? [];

        if (! is_array($args)) {
            throw new RuntimeException('La operacion del lote no trae los argumentos `args`.');
        }

        $sentencia = $this->sentenciaDe(trim($operacion['sql']));

        // La TABLA del legacy: sin fila de tabla en el mapa, no hay destino.
        $regla = $mapa[$sentencia['tabla']]['*'] ?? null;

        if ($regla === null) {
            throw new RuntimeException("La tabla '{$sentencia['tabla']}' del legacy no esta en el mapa de traduccion.");
        }

        if ($regla['new_table'] === null) {
            // La tabla NO se traslada: el mapa dice que aqui no existe, y
            // una operacion sin destino no se inventa.
            Log::warning('sync: la tabla del legacy no se traslada y su operacion se descarta', [
                'tabla' => $sentencia['tabla'],
            ]);

            return ['type' => self::DESCARTE, 'table' => $sentencia['tabla']];
        }

        $tabla = $this->identificadorSeguro($regla['new_table']);

        return match ($sentencia['tipo']) {
            'insert' => $this->traducirInsert($sentencia, $tabla, array_values($args), $contexto, $mapa),
            'update' => $this->traducirUpdate($sentencia, $tabla, array_values($args), $contexto, $mapa),
            'delete' => $this->traducirDelete($sentencia, $tabla, array_values($args), $contexto, $mapa),
        };
    }

    /**
     * La SENTENCIA del legacy, en una de las TRES formas soportadas.
     *
     * @return array{tipo: string, tabla: string, columnas: list<string>, asignaciones: list<array{columna: string, token: string}>, condiciones: list<array{columna: string, token: string}>}
     */
    private function sentenciaDe(string $sql): array
    {
        $limpia = trim($sql);

        // Un `;` FINAL se tolera: el legacy lo pone y no significa nada.
        if (str_ends_with($limpia, ';')) {
            $limpia = trim(substr($limpia, 0, -1));
        }

        if (preg_match(self::RE_INSERT, $limpia, $m) === 1) {
            $columnas = $this->listaDeIdentificadores($m['columnas']);

            // El numero de `?` del `VALUES` tiene que coincidir con el
            // numero de columnas: si no, el lote esta mal formado.
            if (substr_count($m['valores'], '?') !== count($columnas)) {
                throw new RuntimeException('El `INSERT` del legacy trae un numero de `?` que no coincide con el numero de columnas.');
            }

            return [
                'tipo' => 'insert',
                'tabla' => $this->sinComillas($m['tabla']),
                'columnas' => $columnas,
                'asignaciones' => [],
                'condiciones' => [],
            ];
        }

        if (preg_match(self::RE_UPDATE, $limpia, $m) === 1) {
            return [
                'tipo' => 'update',
                'tabla' => $this->sinComillas($m['tabla']),
                'columnas' => [],
                'asignaciones' => $this->asignacionesDe($m['asignaciones']),
                'condiciones' => $this->condicionesDe($m['condiciones']),
            ];
        }

        if (preg_match(self::RE_DELETE, $limpia, $m) === 1) {
            return [
                'tipo' => 'delete',
                'tabla' => $this->sinComillas($m['tabla']),
                'columnas' => [],
                'asignaciones' => [],
                'condiciones' => $this->condicionesDe($m['condiciones']),
            ];
        }

        // Ninguna de las tres formas: el motivo del rechazo.
        if (str_contains($limpia, ';')) {
            throw new RuntimeException('La sentencia del legacy trae un `;` apilado: el lote se rechaza entero.');
        }

        if (preg_match('/\bLIMIT\b/i', $limpia) === 1) {
            throw new RuntimeException('La sentencia del legacy trae `LIMIT`, que PostgreSQL no soporta: el lote se rechaza entero.');
        }

        throw new RuntimeException('La sentencia del legacy no es de las tres formas soportadas (INSERT, UPDATE o DELETE): `'.$this->recortar($limpia).'`.');
    }

    /**
     * Traduce un `INSERT`.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array<string, mixed>
     */
    private function traducirInsert(array $sentencia, string $tabla, array $args, array $contexto, array $mapa): array
    {
        $indice = 0;
        $columnas = [];
        $valores = [];

        foreach ($sentencia['columnas'] as $columnaLegacy) {
            $valor = $this->valorDe('?', $args, $indice);
            $traducida = $this->traducirColumna($sentencia['tabla'], $columnaLegacy, $valor, $contexto, $mapa, false);

            // Una columna que el mapa NO traslada se descarta: su valor no
            // viaja, y las columnas y los valores siguen en paralelo.
            if ($traducida === null) {
                continue;
            }

            $columnas[] = $traducida['columna'];
            $valores[] = $traducida['valor'];
        }

        $this->comprobarArgumentos($indice, count($args));

        return [
            'type' => 'insert',
            'table' => $tabla,
            'columns' => $columnas,
            'values' => $valores,
        ];
    }

    /**
     * Traduce un `UPDATE`.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array<string, mixed>
     */
    private function traducirUpdate(array $sentencia, string $tabla, array $args, array $contexto, array $mapa): array
    {
        $indice = 0;
        $asignaciones = [];

        foreach ($sentencia['asignaciones'] as $asignacion) {
            $valor = $this->valorDe($asignacion['token'], $args, $indice);
            $traducida = $this->traducirColumna($sentencia['tabla'], $asignacion['columna'], $valor, $contexto, $mapa, false);

            if ($traducida === null) {
                continue;
            }

            if (array_key_exists($traducida['columna'], $asignaciones)) {
                throw new RuntimeException("El `UPDATE` del legacy asigna dos veces la misma columna destino '{$traducida['columna']}'.");
            }

            $asignaciones[$traducida['columna']] = $traducida['valor'];
        }

        $where = $this->traducirCondiciones($sentencia, $args, $indice, $contexto, $mapa);

        $this->comprobarArgumentos($indice, count($args));

        return [
            'type' => 'update',
            'table' => $tabla,
            'assignments' => $asignaciones,
            'where' => $where,
        ];
    }

    /**
     * Traduce un `DELETE`.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array<string, mixed>
     */
    private function traducirDelete(array $sentencia, string $tabla, array $args, array $contexto, array $mapa): array
    {
        $indice = 0;
        $where = $this->traducirCondiciones($sentencia, $args, $indice, $contexto, $mapa);

        $this->comprobarArgumentos($indice, count($args));

        return [
            'type' => 'delete',
            'table' => $tabla,
            'where' => $where,
        ];
    }

    /**
     * El `WHERE` traducido: columna de destino => valor.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array<string, mixed>
     */
    private function traducirCondiciones(array $sentencia, array $args, int &$indice, array $contexto, array $mapa): array
    {
        $where = [];

        foreach ($sentencia['condiciones'] as $condicion) {
            $valor = $this->valorDe($condicion['token'], $args, $indice);
            $traducida = $this->traducirColumna($sentencia['tabla'], $condicion['columna'], $valor, $contexto, $mapa, true);

            if ($traducida === null) {
                continue;
            }

            if (array_key_exists($traducida['columna'], $where)) {
                throw new RuntimeException("El `WHERE` del legacy filtra dos veces por la misma columna destino '{$traducida['columna']}'.");
            }

            $where[$traducida['columna']] = $traducida['valor'];
        }

        return $where;
    }

    /**
     * Traduce UNA columna del legacy: su columna de destino y su valor.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     * @return array{columna: string, valor: mixed}|null NULL cuando la columna NO se traslada.
     */
    private function traducirColumna(string $tablaLegacy, string $columnaLegacy, mixed $valor, array $contexto, array $mapa, bool $filtrando): ?array
    {
        $fila = $mapa[$tablaLegacy][$columnaLegacy] ?? null;

        if ($fila === null || $fila['new_table'] === null || $fila['new_column'] === null) {
            if ($filtrando) {
                throw new RuntimeException("El `WHERE` filtra por '{$tablaLegacy}.{$columnaLegacy}', que el mapa NO traslada: no se puede filtrar por algo que no existe.");
            }

            return null;
        }

        return [
            'columna' => $this->identificadorSeguro($fila['new_column']),
            'valor' => $this->aplicarTransform($fila['transform'], $valor, $tablaLegacy, $columnaLegacy, $contexto, $mapa),
        ];
    }

    /**
     * Aplica la TRANSFORMACION del mapa al valor de una columna.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     */
    private function aplicarTransform(?string $transform, mixed $valor, string $tablaLegacy, string $columnaLegacy, array $contexto, array $mapa): mixed
    {
        $partes = $transform === null || trim($transform) === '' ? ['rename'] : explode(':', trim($transform));
        $nombre = array_shift($partes);

        return match ($nombre) {
            'rename' => $this->valorDeFrontera($valor),
            'cast_enum' => $this->valorDeEnum($partes, $valor),
            'resolve_ref' => $this->valorResuelto($partes, $valor, $tablaLegacy, $columnaLegacy, $mapa),
            'inject_store' => $this->valorDelContexto($contexto, 'store_id'),
            'inject_reference' => $this->valorDelContexto($contexto, 'sale_id'),
            'inject_business_date' => $this->valorDelContexto($contexto, 'business_date'),
            default => throw new RuntimeException("La transformacion '{$nombre}' del mapa no existe: el lote se rechaza entero."),
        };
    }

    /**
     * El VALOR de un enum: la transformacion `cast_enum` lleva los pares
     * `legacy:destino` separados por `:`.
     */
    private function valorDeEnum(array $pares, mixed $valor): string
    {
        if (count($pares) % 2 !== 0) {
            throw new RuntimeException('La transformacion `cast_enum` del mapa esta mal formada: los pares van de dos en dos.');
        }

        $clave = $this->claveDeFrontera($valor);
        $destino = null;

        for ($i = 0; $i < count($pares); $i += 2) {
            if ($pares[$i] === $clave) {
                $destino = $pares[$i + 1];

                break;
            }
        }

        if ($destino === null) {
            throw new RuntimeException("El valor '{$clave}' del legacy no esta en los pares del `cast_enum` del mapa: el lote se rechaza entero.");
        }

        return $destino;
    }

    /**
     * El VALOR de una referencia: el legacy manda un identificador de TEXTO
     * y el destino tiene una FK `bigint`.
     *
     * Se busca el id real en la tabla que declara la fila de referencia (la
     * que tiene el MISMO `legacy_column`). Si no se encuentra, el lote se
     * rechaza entero: un id inventado seria una venta que no existe.
     *
     * @param  array<string, array<string, array{new_table: ?string, new_column: ?string, transform: ?string}>>  $mapa
     */
    private function valorResuelto(array $partes, mixed $valor, string $tablaLegacy, string $columnaLegacy, array $mapa): int
    {
        if (count($partes) !== 1 || trim($partes[0]) === '') {
            throw new RuntimeException('La transformacion `resolve_ref` del mapa necesita la tabla de referencia.');
        }

        $tablaReferencia = trim($partes[0]);
        $fila = $mapa[$tablaReferencia][$columnaLegacy] ?? null;

        if ($fila === null || $fila['new_table'] === null || $fila['new_column'] === null) {
            throw new RuntimeException("La fila de referencia '{$tablaReferencia}.{$columnaLegacy}' no esta en el mapa.");
        }

        $tabla = $this->identificadorSeguro($fila['new_table']);
        $columna = $this->identificadorSeguro($fila['new_column']);
        $clave = $this->claveDeFrontera($valor);

        $id = $this->connection->table($tabla)
            ->where($columna, $clave)
            ->orderBy(self::CLAVE_PRIMARIA)
            ->value(self::CLAVE_PRIMARIA);

        if ($id === null) {
            throw new RuntimeException("No se encuentra '{$tabla}.{$columna}' con el valor '{$clave}': el lote se rechaza entero.");
        }

        return (int) $id;
    }

    /**
     * Un valor del CONTEXTO del lote.
     */
    private function valorDelContexto(array $contexto, string $clave): mixed
    {
        if (! array_key_exists($clave, $contexto) || $contexto[$clave] === null || $contexto[$clave] === '') {
            throw new RuntimeException("El contexto del lote no trae '{$clave}': el lote se rechaza entero.");
        }

        return $contexto[$clave];
    }

    /**
     * El VALOR de la frontera: el legacy HTML-escapea sus entradas, asi que
     * el maestro recibe `&amp;` donde deberia haber `&`.
     *
     * El des-escapeo ocurre UNA SOLA VEZ, aqui. Un importe viaja como TEXTO
     * decimal: un `float` no es dinero.
     */
    private function valorDeFrontera(mixed $valor): mixed
    {
        if (is_string($valor)) {
            return html_entity_decode($valor, ENT_QUOTES, 'UTF-8');
        }

        if (is_float($valor)) {
            return number_format($valor, self::ESCALA_DECIMAL, '.', '');
        }

        return $valor;
    }

    /**
     * La CLAVE de un valor: su forma de TEXTO, para compararlo con los
     * pares de un enum o con la columna de una referencia.
     *
     * El des-escapeo tambien ocurre aqui, y solo aqui: un valor pasa por
     * la frontera UNA vez.
     */
    private function claveDeFrontera(mixed $valor): string
    {
        if (is_string($valor)) {
            return html_entity_decode($valor, ENT_QUOTES, 'UTF-8');
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if ($valor === null) {
            return '';
        }

        if (is_scalar($valor)) {
            return (string) $valor;
        }

        throw new RuntimeException('Un valor del legacy que no es texto ni numero no se puede traducir.');
    }

    /**
     * El VALOR de una posicion: un placeholder `?` consume un argumento del
     * lote; un literal se resuelve en el sitio.
     */
    private function valorDe(string $token, array $args, int &$indice): mixed
    {
        if ($token !== '?') {
            return $this->literal($token);
        }

        if (! array_key_exists($indice, $args)) {
            throw new RuntimeException('La sentencia del legacy trae mas placeholders que argumentos.');
        }

        $valor = $args[$indice];
        $indice++;

        return $valor;
    }

    /**
     * Un LITERAL de la sentencia: no es un argumento del lote, es texto que
     * el legacy escribio en el SQL.
     */
    private function literal(string $token): mixed
    {
        if (preg_match("/^'(.*)'$/s", $token, $m) === 1) {
            return str_replace("''", "'", $m[1]);
        }

        if (preg_match('/^"(.*)"$/s', $token, $m) === 1) {
            return str_replace('""', '"', $m[1]);
        }

        if (preg_match('/^-?\d+$/', $token) === 1) {
            return (int) $token;
        }

        // Un numero con decimales viaja como TEXTO decimal, nunca como
        // `float`.
        return (string) BigDecimal::of($token)->toScale(self::ESCALA_DECIMAL, RoundingMode::HALF_UP);
    }

    /**
     * Una lista de ASIGNACIONES: `columna = valor`.
     *
     * @return list<array{columna: string, token: string}>
     */
    private function asignacionesDe(string $lista): array
    {
        preg_match_all(self::RE_ASIGNACION, $lista, $coincidencias, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $asignaciones = [];
        $fin = 0;

        foreach ($coincidencias as $coincidencia) {
            [$texto, $inicio] = $coincidencia[0];

            // Entre dos asignaciones solo puede haber una coma.
            $separador = substr($lista, $fin, $inicio - $fin);

            if ($separador !== '' && preg_match('/^\s*,\s*$/', $separador) !== 1) {
                throw new RuntimeException('El legacy escribe un `SET` que no se reconoce: `'.$this->recortar($lista).'`.');
            }

            $asignaciones[] = [
                'columna' => $this->sinComillas($coincidencia['columna'][0]),
                'token' => $coincidencia['valor'][0],
            ];

            $fin = $inicio + strlen($texto);
        }

        if ($asignaciones === [] || trim(substr($lista, $fin)) !== '') {
            throw new RuntimeException('El legacy escribe un `SET` que no se reconoce: `'.$this->recortar($lista).'`.');
        }

        return $asignaciones;
    }

    /**
     * Una lista de CONDICIONES unidas por `AND` u `OR`.
     *
     * @return list<array{columna: string, token: string}>
     */
    private function condicionesDe(string $lista): array
    {
        $partes = preg_split('/(\s+AND\s+|\s+OR\s+)/i', $lista, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($partes === false) {
            throw new RuntimeException('El legacy escribe un `WHERE` que no se reconoce: `'.$this->recortar($lista).'`.');
        }

        $condiciones = [];

        // Los separadores van en las posiciones impares.
        for ($i = 0; $i < count($partes); $i += 2) {
            if (isset($partes[$i + 1]) && strtoupper(trim($partes[$i + 1])) === 'OR') {
                throw new RuntimeException('El `WHERE` del legacy usa `OR`, que el destino no puede expresar como filtro por columnas: el lote se rechaza entero.');
            }

            $condicion = trim($partes[$i]);

            if (preg_match(self::RE_CONDICION, $condicion, $m) !== 1) {
                throw new RuntimeException('El legacy escribe una condicion que no se reconoce: `'.$this->recortar($condicion).'`.');
            }

            $condiciones[] = [
                'columna' => $this->sinComillas($m['columna']),
                'token' => $m['valor'],
            ];
        }

        if ($condiciones === []) {
            throw new RuntimeException('El legacy escribe un `WHERE` vacio.');
        }

        return $condiciones;
    }

    /**
     * Una lista de IDENTIFICADORES separados por comas.
     *
     * @return list<string>
     */
    private function listaDeIdentificadores(string $lista): array
    {
        $identificadores = [];

        foreach (explode(',', $lista) as $parte) {
            $parte = trim($parte);

            if (preg_match('/^'.self::IDENTIFICADOR.'$/', $parte) !== 1) {
                throw new RuntimeException("El legacy escribe un identificador que no se reconoce: `{$parte}`.");
            }

            $identificadores[] = $this->sinComillas($parte);
        }

        return $identificadores;
    }

    /**
     * El identificador SIN las comillas invertidas.
     */
    private function sinComillas(string $identificador): string
    {
        if (strlen($identificador) > 1 && str_starts_with($identificador, '`') && str_ends_with($identificador, '`')) {
            return substr($identificador, 1, -1);
        }

        return $identificador;
    }

    /**
     * Un IDENTIFICADOR del destino: sale del mapa, y el mapa es DATO. Se
     * valida igual, porque un identificador no es un `string` cualquiera.
     */
    private function identificadorSeguro(string $identificador): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identificador) !== 1) {
            throw new RuntimeException("El mapa de traduccion trae un identificador que no es valido: `{$identificador}`.");
        }

        return $identificador;
    }

    /**
     * Los argumentos del lote tienen que_quadrar con los placeholders.
     */
    private function comprobarArgumentos(int $consumidos, int $disponibles): void
    {
        if ($consumidos !== $disponibles) {
            throw new RuntimeException("La sentencia del legacy trae {$consumidos} placeholders y el lote trae {$disponibles} argumentos: no cuadran.");
        }
    }

    /**
     * Un texto recortado, para los mensajes de error.
     */
    private function recortar(string $texto): string
    {
        return strlen($texto) > 200 ? substr($texto, 0, 200).'...' : $texto;
    }
}
