<?php

namespace App\Fiscal;

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\SignerModel;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El REGISTRO de perfiles fiscales: el alta y la edicion de los regimenes.
 *
 * REGLA que fija esta clase: *un perfil fiscal es un REGIMEN, y un regimen se
 * declara una vez*.
 *
 *  - EL ALTA declara el regimen: su pais, su codigo (su clave natural), su
 *    nombre, quien firma, su moneda y desde cuando esta en vigor. La MONEDA
 *    es obligatoria: el emisor la toma del perfil, y un perfil sin moneda no
 *    puede emitir;
 *  - EL CODIGO es la clave natural del perfil dentro de su pais, y es UNICO
 *    ahi: dos regimenes con el mismo codigo serian el mismo regimen;
 *  - LA EDICION NO MUTA EL REGIMEN: el codigo, el pais, la moneda, la
 *    vigencia, el modelo de firma y las reglas del comprobante (el redondeo,
 *    el impuesto incluido, si hace falta numero de control) se declaran al
 *    dar de alta. Editar un perfil es corregir COMO SE ESCRIBE (la
 *    descripcion tecnica: la version del esquema, el espacio de nombres, el
 *    XSD, el patron del identificativo fiscal) y su nombre.
 *
 * Y POR QUE LA VIGENCIA NO SE EDITA: la vigencia decide que perfil resuelve
 * un pais (`StoreFiscalProfileResolver`), y un regimen que se mueve de
 * fecha cambia el regimen de las tiendas que no declaran ninguno. Un regimen
 * nuevo es una FILA nueva, no una fila mutada.
 */
final readonly class FiscalProfileRegistry
{
    /**
     * El tope del CODIGO: es lo que admite la columna.
     */
    private const CODIGO_MAXIMO = 50;

    /**
     * El tope del NOMBRE: es lo que admite la columna.
     */
    private const NOMBRE_MAXIMO = 100;

    /**
     * El tope del ESTANDAR de documento: es lo que admite la columna.
     */
    private const ESTANDAR_MAXIMO = 50;

    /**
     * El tope de la VERSION del esquema: es lo que admite la columna.
     */
    private const VERSION_MAXIMA = 20;

    /**
     * El tope de la REFERENCIA del esquema: es lo que admite la columna.
     */
    private const REFERENCIA_MAXIMA = 255;

    /**
     * El tope del ESQUEMA de numeracion: es lo que admite la columna.
     */
    private const NUMERACION_MAXIMA = 50;

    public function __construct(private Connection $connection) {}

    /**
     * El ALTA de un perfil fiscal: el regimen de un pais.
     *
     * @param  array<string, mixed>  $datos
     * @return int El identificador del perfil creado.
     *
     * @throws RuntimeException Si algo de lo declarado no vale.
     */
    public function create(array $datos): int
    {
        $paisId = (int) ($datos['country_id'] ?? null);
        $codigo = $this->codigo($datos['code'] ?? null);
        $nombre = $this->nombre($datos['name'] ?? null);
        $firmante = $this->firmante($datos['signer_model'] ?? null);
        $monedaId = $this->moneda($datos['default_currency_id'] ?? null);

        $this->exigePais($paisId);
        $this->exigeCodigoLibre($paisId, $codigo);

        $fila = [
            'country_id' => $paisId,
            'code' => $codigo,
            'name' => $nombre,
            'document_standard' => $this->texto($datos['document_standard'] ?? null, self::ESTANDAR_MAXIMO),
            'schema_version' => $this->texto($datos['schema_version'] ?? null, self::VERSION_MAXIMA),
            'schema_reference_url' => $this->texto($datos['schema_reference_url'] ?? null, self::REFERENCIA_MAXIMA),
            'xml_namespace' => $this->texto($datos['xml_namespace'] ?? null, self::REFERENCIA_MAXIMA),
            'xsd_url' => $this->texto($datos['xsd_url'] ?? null, self::REFERENCIA_MAXIMA),
            'id_scheme_pattern' => $this->texto($datos['id_scheme_pattern'] ?? null, self::REFERENCIA_MAXIMA),
            'numbering_scheme' => $this->texto($datos['numbering_scheme'] ?? null, self::NUMERACION_MAXIMA),
            'signer_model' => $firmante,
            'default_currency_id' => $monedaId,
            'effective_from' => $this->fecha($datos['effective_from'] ?? null),
            'effective_to' => $this->fecha($datos['effective_to'] ?? null),
            'requires_control_number' => (bool) ($datos['requires_control_number'] ?? false),
            'authorized_range_required' => (bool) ($datos['authorized_range_required'] ?? false),
            'holes_allowed' => (bool) ($datos['holes_allowed'] ?? true),
            'rounding_rule' => $this->redondeo($datos['rounding_rule'] ?? null),
            'tax_inclusive_default' => (bool) ($datos['tax_inclusive_default'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->exigeVigencia($fila['effective_from'], $fila['effective_to']);

        return (int) $this->connection->table('fiscal_profiles')->insertGetId($fila);
    }

    /**
     * La EDICION de un perfil: su nombre y su descripcion tecnica.
     *
     * Y LO QUE NO SE EDITA, Y POR QUE: el codigo (es la clave natural), el
     * pais, la moneda (es la del comprobante), la vigencia (decide que
     * regimen resuelve un pais), el modelo de firma y las reglas del
     * comprobante. Un regimen se DECLARA, y lo que se corrige despues es como
     * se escribe.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws RuntimeException Si el perfil no existe, o si algo de lo
     *                          declarado no vale.
     */
    public function update(int $profileId, array $datos): void
    {
        $perfil = $this->connection->table('fiscal_profiles')->where('id', $profileId)->first();

        if ($perfil === null) {
            throw new RuntimeException("El perfil fiscal {$profileId} no existe.");
        }

        $fila = ['updated_at' => now()];

        // SOLO lo que viene en la carga: una edicion parcial no borra lo que
        // no se ha tocado.
        if (array_key_exists('name', $datos)) {
            $fila['name'] = $this->nombre($datos['name']);
        }

        if (array_key_exists('document_standard', $datos)) {
            $fila['document_standard'] = $this->texto($datos['document_standard'], self::ESTANDAR_MAXIMO);
        }

        if (array_key_exists('schema_version', $datos)) {
            $fila['schema_version'] = $this->texto($datos['schema_version'], self::VERSION_MAXIMA);
        }

        if (array_key_exists('schema_reference_url', $datos)) {
            $fila['schema_reference_url'] = $this->texto($datos['schema_reference_url'], self::REFERENCIA_MAXIMA);
        }

        if (array_key_exists('xml_namespace', $datos)) {
            $fila['xml_namespace'] = $this->texto($datos['xml_namespace'], self::REFERENCIA_MAXIMA);
        }

        if (array_key_exists('xsd_url', $datos)) {
            $fila['xsd_url'] = $this->texto($datos['xsd_url'], self::REFERENCIA_MAXIMA);
        }

        if (array_key_exists('id_scheme_pattern', $datos)) {
            $fila['id_scheme_pattern'] = $this->texto($datos['id_scheme_pattern'], self::REFERENCIA_MAXIMA);
        }

        if (array_key_exists('numbering_scheme', $datos)) {
            $fila['numbering_scheme'] = $this->texto($datos['numbering_scheme'], self::NUMERACION_MAXIMA);
        }

        $this->connection->table('fiscal_profiles')->where('id', $profileId)->update($fila);
    }

    /**
     * El PAIS del regimen tiene que existir.
     *
     * @throws RuntimeException Si el pais no existe.
     */
    private function exigePais(int $paisId): void
    {
        if ($paisId <= 0) {
            throw new RuntimeException(
                'El perfil fiscal necesita su pais: un perfil fiscal es el regimen de un pais.'
            );
        }

        if (! $this->connection->table('countries')->where('id', $paisId)->exists()) {
            throw new RuntimeException(
                "El pais {$paisId} no existe: un perfil fiscal es el regimen de un pais."
            );
        }
    }

    /**
     * El CODIGO es la clave natural del perfil dentro de su pais.
     *
     * @throws RuntimeException Si el codigo no vale o ya existe.
     */
    private function exigeCodigoLibre(int $paisId, string $codigo): void
    {
        $existe = $this->connection->table('fiscal_profiles')
            ->where('country_id', $paisId)
            ->where('code', $codigo)
            ->exists();

        if ($existe) {
            throw new RuntimeException(
                "El pais {$paisId} ya tiene un perfil con el codigo «{$codigo}»: "
                .'el codigo es la clave natural del perfil y es unico dentro del pais.'
            );
        }
    }

    /**
     * El CODIGO del perfil.
     *
     * @throws RuntimeException Si el codigo no vale.
     */
    private function codigo(mixed $codigo): string
    {
        $valor = $codigo === null ? '' : trim((string) $codigo);

        if ($valor === '') {
            throw new RuntimeException('El perfil fiscal necesita un codigo: es su clave natural.');
        }

        if (mb_strlen($valor) > self::CODIGO_MAXIMO) {
            throw new RuntimeException(
                'El codigo del perfil fiscal no puede tener mas de '.self::CODIGO_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * El NOMBRE del perfil: el texto que ve la persona.
     *
     * @throws RuntimeException Si el nombre no vale.
     */
    private function nombre(mixed $nombre): string
    {
        $valor = $nombre === null ? '' : trim((string) $nombre);

        if ($valor === '') {
            throw new RuntimeException('El perfil fiscal necesita un nombre.');
        }

        if (mb_strlen($valor) > self::NOMBRE_MAXIMO) {
            throw new RuntimeException(
                'El nombre del perfil fiscal no puede tener mas de '.self::NOMBRE_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * Quien FIRMA: el modelo de firma es parte del regimen.
     *
     * @throws RuntimeException Si el modelo de firma no existe.
     */
    private function firmante(mixed $firmante): string
    {
        $valor = $firmante === null ? '' : trim((string) $firmante);

        if (SignerModel::tryFrom($valor) === null) {
            throw new RuntimeException(
                "El modelo de firma '{$valor}' no existe: solo se admiten "
                .implode(', ', array_column(SignerModel::cases(), 'value')).'.'
            );
        }

        return $valor;
    }

    /**
     * La MONEDA del regimen: es la del comprobante, y sin ella no se emite.
     *
     * @throws RuntimeException Si la moneda no existe.
     */
    private function moneda(mixed $monedaId): int
    {
        if ($monedaId === null || $monedaId === '') {
            throw new RuntimeException(
                'El perfil fiscal necesita su moneda: el emisor la toma del perfil, y un perfil '
                .'sin moneda no puede emitir.'
            );
        }

        $id = (int) $monedaId;

        if (! $this->connection->table('currencies')->where('id', $id)->exists()) {
            throw new RuntimeException("La moneda {$id} no existe en el catalogo.");
        }

        return $id;
    }

    /**
     * La REGLA de redondeo: el motor de impuestos la lee del perfil, y solo
     * admite las del conjunto cerrado.
     *
     * @throws RuntimeException Si la regla no existe.
     */
    private function redondeo(mixed $regla): ?string
    {
        if ($regla === null || $regla === '') {
            return null;
        }

        $valor = trim((string) $regla);

        if (RoundingRule::tryFrom($valor) === null) {
            throw new RuntimeException(
                "La regla de redondeo '{$valor}' no existe: solo se admiten "
                .implode(', ', array_column(RoundingRule::cases(), 'value')).'.'
            );
        }

        return $valor;
    }

    /**
     * La VIGENCIA del regimen.
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
                "La vigencia del perfil va de {$desde} a {$hasta} y no puede terminar antes de empezar."
            );
        }
    }

    /**
     * Un TEXTO de la descripcion tecnica del regimen.
     *
     * @throws RuntimeException Si el texto no cabe.
     */
    private function texto(mixed $valor, int $tope): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $tope) {
            throw new RuntimeException(
                "Un dato de la descripcion del perfil no puede tener mas de {$tope} caracteres: "
                .'es lo que admite la columna.'
            );
        }

        return $texto;
    }
}
