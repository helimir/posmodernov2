<?php

namespace App\Fiscal;

use App\Fiscal\Enums\SeriesAuthority;
use App\Fiscal\Enums\SeriesRole;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El REGISTRO de series fiscales: el alta y la edicion de la numeracion
 * declarada de una tienda.
 *
 * REGLA que fija esta clase: *una serie DECLARA su numeracion, y lo declara
 * UNA vez*.
 *
 *  - EL ALTA abre la serie en el principio de su rango: `next_number` es el
 *    `from_number` y NO se reparte ningun numero. El correlativo lo reserva
 *    el DOMINIO (`NumberSeries`, con `UPDATE ... RETURNING`), y un alta que
 *    repartiera un numero seria un numero quemado sin traza;
 *  - UNA TIENDA TIENE UNA SERIE DE CADA PAPEL dentro de un perfil: el
 *    resolutor (`PosContext`) toma la primera por identificador, y con dos
 *    la numeracion dependeria del orden de las filas;
 *  - LA EDICION NO RENUMERA: el perfil, la tienda, el papel, la autoridad, el
 *    prefijo, el rango y el contador son la identidad de la numeracion, y se
 *    declaran una vez. Editar una serie es cerrar su vigencia, anotar su
 *    autorizacion y decir que imprenta la respalda.
 *
 * Y LOS IMPORTES no viajan por aqui: una serie no tiene importes, tiene
 * numeros.
 */
final readonly class FiscalSeriesRegistry
{
    /**
     * El primer numero de un rango: el correlativo del documento empieza en
     * uno, y un rango que empieza en cero tiene un numero que no existe.
     */
    private const PRIMER_NUMERO = 1;

    /**
     * El tope del PREFIJO: es lo que admite la columna.
     */
    private const PREFIJO_MAXIMO = 30;

    /**
     * El tope de la REFERENCIA de autorizacion: es lo que admite la columna.
     */
    private const REFERENCIA_MAXIMA = 255;

    /**
     * Los digitos del IDENTIFICADOR de control: es un identificador de dos
     * digitos (art. 9.1 y 27), y la primera asignacion es `00`.
     */
    private const IDENTIFICADOR_DIGITOS = 2;

    /**
     * El tope del SECUENCIAL de control: hasta ocho digitos (art. 9.1 y 27).
     */
    private const SECUENCIAL_MAXIMO = 8;

    public function __construct(private Connection $connection) {}

    /**
     * El ALTA de una serie: la numeracion declarada de una tienda.
     *
     * @param  array<string, mixed>  $datos
     * @return int El identificador de la serie creada.
     *
     * @throws RuntimeException Si algo de lo declarado no vale.
     */
    public function create(array $datos): int
    {
        $perfilId = (int) ($datos['fiscal_profile_id'] ?? null);
        $tiendaId = (int) ($datos['store_id'] ?? null);
        $papel = (string) ($datos['series_role'] ?? '');
        $autoridad = (string) ($datos['assigned_by'] ?? '');

        $this->exigePerfil($perfilId);
        $this->exigeTienda($tiendaId);
        $this->exigePapel($papel);
        $this->exigeAutoridad($autoridad);
        $this->exigeUnica($perfilId, $tiendaId, $papel);

        $proveedorId = $this->proveedorId($perfilId, $datos['provider_id'] ?? null);

        $desde = (int) ($datos['from_number'] ?? 0);
        $hasta = (int) ($datos['to_number'] ?? 0);

        $this->exigeRango($desde, $hasta);

        $fila = [
            'fiscal_profile_id' => $perfilId,
            'store_id' => $tiendaId,
            'provider_id' => $proveedorId,
            'series_role' => $papel,
            'assigned_by' => $autoridad,
            'prefix' => $this->prefijo($datos['prefix'] ?? null),
            // EL CONTADOR EMPIEZA EN EL PRINCIPIO DEL RANGO: el alta no
            // reparte ningun numero, y el contador solo lo mueve el dominio.
            'next_number' => $desde,
            'from_number' => $desde,
            'to_number' => $hasta,
            'valid_from' => $this->fecha($datos['valid_from'] ?? null),
            'valid_to' => $this->fecha($datos['valid_to'] ?? null),
            'credential_ref' => $this->referencia($datos['credential_ref'] ?? null),
            'control_identifier' => $this->identificador($datos['control_identifier'] ?? null),
            'sequence_length' => $this->secuencial($datos['sequence_length'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->exigeVigencia($fila['valid_from'], $fila['valid_to']);

        return (int) $this->connection->table('fiscal_series')->insertGetId($fila);
    }

    /**
     * La EDICION de una serie: su vigencia, su autorizacion y su imprenta.
     *
     * Y LO QUE NO SE EDITA, Y POR QUE: el perfil, la tienda, el papel, la
     * autoridad, el prefijo, el rango y el contador. Son la identidad de la
     * numeracion, y una numeracion que se renumera no se puede defender
     * (ADR-0001): el numero ya emitido no vuelve.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws RuntimeException Si la serie no existe, o si algo de lo
     *                          declarado no vale.
     */
    public function update(int $seriesId, array $datos): void
    {
        $serie = $this->connection->table('fiscal_series')->where('id', $seriesId)->first();

        if ($serie === null) {
            throw new RuntimeException("La serie fiscal {$seriesId} no existe.");
        }

        $fila = ['updated_at' => now()];

        // SOLO lo que viene en la carga: una edicion parcial no borra lo que
        // no se ha tocado.
        if (array_key_exists('provider_id', $datos)) {
            $fila['provider_id'] = $this->proveedorId(
                (int) $serie->fiscal_profile_id,
                $datos['provider_id'],
            );
        }

        if (array_key_exists('valid_from', $datos)) {
            $fila['valid_from'] = $this->fecha($datos['valid_from']);
        }

        if (array_key_exists('valid_to', $datos)) {
            $fila['valid_to'] = $this->fecha($datos['valid_to']);
        }

        if (array_key_exists('credential_ref', $datos)) {
            $fila['credential_ref'] = $this->referencia($datos['credential_ref']);
        }

        // La VIGENCIA se comprueba contra la que hay: la de la fila cuando
        // la carga no la trae, y la de la carga cuando la trae.
        $this->exigeVigencia(
            array_key_exists('valid_from', $fila) ? $fila['valid_from'] : $this->fechaDeLaFila($serie->valid_from),
            array_key_exists('valid_to', $fila) ? $fila['valid_to'] : $this->fechaDeLaFila($serie->valid_to),
        );

        $this->connection->table('fiscal_series')->where('id', $seriesId)->update($fila);
    }

    /**
     * El PERFIL fiscal tiene que existir: la serie es de un regimen.
     *
     * @throws RuntimeException Si el perfil no existe.
     */
    private function exigePerfil(int $perfilId): void
    {
        if ($perfilId <= 0) {
            throw new RuntimeException(
                'La serie fiscal necesita su perfil: una serie declara el regimen que la reparte.'
            );
        }

        if (! $this->connection->table('fiscal_profiles')->where('id', $perfilId)->exists()) {
            throw new RuntimeException(
                "El perfil fiscal {$perfilId} no existe: una serie declara el regimen que la reparte."
            );
        }
    }

    /**
     * La TIENDA tiene que existir: la serie es de una tienda.
     *
     * @throws RuntimeException Si la tienda no existe.
     */
    private function exigeTienda(int $tiendaId): void
    {
        if ($tiendaId <= 0) {
            throw new RuntimeException(
                'La serie fiscal necesita su tienda: una serie es la numeracion de una tienda.'
            );
        }

        if (! $this->connection->table('stores')->where('id', $tiendaId)->exists()) {
            throw new RuntimeException(
                "La tienda {$tiendaId} no existe: una serie es la numeracion de una tienda."
            );
        }
    }

    /**
     * El PAPEL de la serie tiene que ser uno de los del conjunto cerrado.
     *
     * @throws RuntimeException Si el papel no existe.
     */
    private function exigePapel(string $papel): void
    {
        if (SeriesRole::tryFrom($papel) === null) {
            throw new RuntimeException(
                "El papel de serie '{$papel}' no existe: solo se admiten "
                .implode(', ', array_column(SeriesRole::cases(), 'value')).'.'
            );
        }
    }

    /**
     * La AUTORIDAD de la serie tiene que ser una de las del conjunto cerrado.
     *
     * @throws RuntimeException Si la autoridad no existe.
     */
    private function exigeAutoridad(string $autoridad): void
    {
        if (SeriesAuthority::tryFrom($autoridad) === null) {
            throw new RuntimeException(
                "La autoridad '{$autoridad}' no existe: solo se admiten "
                .implode(', ', array_column(SeriesAuthority::cases(), 'value')).'.'
            );
        }
    }

    /**
     * UNA SERIE DE CADA PAPEL por tienda y perfil.
     *
     * @throws RuntimeException Si ya existe una serie de ese papel.
     */
    private function exigeUnica(int $perfilId, int $tiendaId, string $papel): void
    {
        $existe = $this->connection->table('fiscal_series')
            ->where('fiscal_profile_id', $perfilId)
            ->where('store_id', $tiendaId)
            ->where('series_role', $papel)
            ->exists();

        if ($existe) {
            throw new RuntimeException(
                "La tienda {$tiendaId} ya tiene una serie de papel '{$papel}' para el perfil {$perfilId}: "
                .'una tienda tiene UNA serie de cada papel dentro de un perfil, porque el resolutor toma '
                .'la primera y con dos la numeracion dependeria del orden de las filas.'
            );
        }
    }

    /**
     * La IMPRENTA que respalda la serie, si la declara.
     *
     * REGLA que fija este metodo: *la imprenta pertenece al MISMO regimen
     * que la serie*. Una serie del perfil A con la imprenta del perfil B es
     * una serie que dice una cosa y significa otra: el perfil es el regimen,
     * y la imprenta es un actor de ESE regimen. El esquema no lo impone (la
     * FK es suelta), asi que lo impone la pantalla.
     *
     * @param  int  $perfilId  El perfil de la serie.
     *
     * @throws RuntimeException Si el proveedor no existe o es de otro perfil.
     */
    private function proveedorId(int $perfilId, mixed $proveedorId): ?int
    {
        if ($proveedorId === null || $proveedorId === '') {
            return null;
        }

        $id = (int) $proveedorId;

        $proveedor = $this->connection->table('fiscal_providers')->where('id', $id)->first();

        if ($proveedor === null) {
            throw new RuntimeException(
                "El proveedor fiscal {$id} no existe: una serie puede decir que imprenta respalda su rango."
            );
        }

        if ((int) $proveedor->fiscal_profile_id !== $perfilId) {
            throw new RuntimeException(
                "El proveedor fiscal '{$proveedor->code}' es del perfil {$proveedor->fiscal_profile_id} y la serie "
                ."es del perfil {$perfilId}: la imprenta que respalda un rango es del MISMO regimen que la serie."
            );
        }

        return $id;
    }

    /**
     * El RANGO autorizado: el primero, el ultimo y que el ultimo no sea
     * anterior al primero.
     *
     * @throws RuntimeException Si el rango no vale.
     */
    private function exigeRango(int $desde, int $hasta): void
    {
        if ($desde < self::PRIMER_NUMERO) {
            throw new RuntimeException(
                "El rango autorizado empieza en {$desde} y un rango empieza en "
                .self::PRIMER_NUMERO.' o mas.'
            );
        }

        if ($hasta < $desde) {
            throw new RuntimeException(
                "El rango autorizado va de {$desde} a {$hasta} y el ultimo numero no puede ser "
                .'anterior al primero.'
            );
        }
    }

    /**
     * El PREFIJO de la serie: el texto que precede al numero del documento.
     *
     * @throws RuntimeException Si el prefijo no cabe.
     */
    private function prefijo(mixed $prefijo): ?string
    {
        if ($prefijo === null) {
            return null;
        }

        $valor = trim((string) $prefijo);

        if ($valor === '') {
            return null;
        }

        if (mb_strlen($valor) > self::PREFIJO_MAXIMO) {
            throw new RuntimeException(
                'El prefijo de la serie no puede tener mas de '.self::PREFIJO_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * La REFERENCIA a la autorizacion: una referencia, nunca el secreto.
     *
     * @throws RuntimeException Si la referencia no cabe.
     */
    private function referencia(mixed $referencia): ?string
    {
        if ($referencia === null) {
            return null;
        }

        $valor = trim((string) $referencia);

        if ($valor === '') {
            return null;
        }

        if (mb_strlen($valor) > self::REFERENCIA_MAXIMA) {
            throw new RuntimeException(
                'La referencia de la autorizacion no puede tener mas de '.self::REFERENCIA_MAXIMA
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * La VIGENCIA de la serie: la fecha en la que empieza a valer y la fecha
     * en la que deja de valer.
     *
     * @throws RuntimeException Si la fecha no es una fecha.
     */
    private function fecha(mixed $fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        $valor = trim((string) $fecha);

        $comprobada = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        if ($comprobada === false || $comprobada->format('Y-m-d') !== $valor) {
            throw new RuntimeException("La fecha '{$valor}' no es una fecha valida (AAAA-MM-DD).");
        }

        return $valor;
    }

    /**
     * La VIGENCIA tiene que ser coherente: no puede terminar antes de
     * empezar.
     *
     * @throws RuntimeException Si la vigencia no vale.
     */
    private function exigeVigencia(?string $desde, ?string $hasta): void
    {
        if ($desde === null || $hasta === null) {
            return;
        }

        if ($hasta < $desde) {
            throw new RuntimeException(
                "La vigencia de la serie va de {$desde} a {$hasta} y no puede terminar antes de empezar."
            );
        }
    }

    /**
     * La FECHA de una fila, en el formato que se guarda.
     */
    private function fechaDeLaFila(mixed $fecha): ?string
    {
        if ($fecha === null) {
            return null;
        }

        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format('Y-m-d');
        }

        return (string) $fecha;
    }

    /**
     * El IDENTIFICADOR de control: dos digitos, como el art. 9.1 y 27.
     *
     * @throws RuntimeException Si el identificador no vale.
     */
    private function identificador(mixed $identificador): ?string
    {
        if ($identificador === null || $identificador === '') {
            return null;
        }

        $valor = trim((string) $identificador);

        if (preg_match('/^\d{'.self::IDENTIFICADOR_DIGITOS.'}$/', $valor) !== 1) {
            throw new RuntimeException(
                "El identificador de control '{$valor}' no vale: son "
                .self::IDENTIFICADOR_DIGITOS.' digitos, y la primera asignacion de una serie es «00».'
            );
        }

        return $valor;
    }

    /**
     * El SECUENCIAL de control: hasta ocho digitos (art. 9.1 y 27).
     *
     * @throws RuntimeException Si el secuencial no vale.
     */
    private function secuencial(mixed $secuencial): ?int
    {
        if ($secuencial === null || $secuencial === '') {
            return null;
        }

        $valor = (int) $secuencial;

        if ($valor < 1 || $valor > self::SECUENCIAL_MAXIMO) {
            throw new RuntimeException(
                "El secuencial de control '{$valor}' no vale: son de 1 a "
                .self::SECUENCIAL_MAXIMO.' digitos (art. 9.1 y 27).'
            );
        }

        return $valor;
    }
}
