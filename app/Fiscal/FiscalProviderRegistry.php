<?php

namespace App\Fiscal;

use App\Domain\Fiscal\Enums\ProviderKind;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El REGISTRO de proveedores fiscales: el alta y la edicion de los canales
 * de emision.
 *
 * REGLA que fija esta clase: *el canal fiscal es DATO, y el secreto de un
 * canal NUNCA esta en la base de datos*.
 *
 *  - EL CANAL (`kind`) es el tipo de actor por el que emite: la imprenta
 *    local, un proveedor externo o el portal de la autoridad. Es DATO, y por
 *    eso no se cambia: cambiarlo seria cambiar como se emite y quien
 *    asigna el numero de control. Un canal equivocado se apaga, y se declara
 *    otro;
 *  - EL CODIGO es la clave natural del proveedor dentro de su perfil, y es
 *    UNICO ahi;
 *  - EL PREFIJO de las credenciales es lo que se declara: el secreto se
 *    resuelve en RUNTIME del entorno (`ProviderCredentialResolver`), y la
 *    fila solo dice de que variable sale. El canal local no tiene credenciales
 *    y su prefijo en `null` es una configuracion legitima;
 *  - EL INTERRUPTOR (`is_active`) apaga el canal: por un canal desactivado no
 *    se emite, y apagar un canal es como se corta un canal roto sin tocar la
 *    numeracion.
 *
 * Y UN CANAL SIN IMPLEMENTACION NO SE EMULA: la pantalla lo avisa
 * (`FiscalChannels`), y el registro no lo esconde.
 */
final readonly class FiscalProviderRegistry
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
     * El tope de la DIRECCION del canal: es lo que admite la columna.
     */
    private const DIRECCION_MAXIMA = 255;

    /**
     * El tope del ESQUEMA de autenticacion: es lo que admite la columna.
     */
    private const AUTENTICACION_MAXIMA = 50;

    /**
     * El tope de la REFERENCIA de credenciales: es lo que admite la columna.
     */
    private const REFERENCIA_MAXIMA = 255;

    /**
     * El tope del PREFIJO del entorno: es lo que admite la columna.
     */
    private const PREFIJO_MAXIMO = 100;

    public function __construct(private Connection $connection) {}

    /**
     * El ALTA de un proveedor: el canal de emision de un regimen.
     *
     * @param  array<string, mixed>  $datos
     * @return int El identificador del proveedor creado.
     *
     * @throws RuntimeException Si algo de lo declarado no vale.
     */
    public function create(array $datos): int
    {
        $perfilId = (int) ($datos['fiscal_profile_id'] ?? null);
        $codigo = $this->codigo($datos['code'] ?? null);
        $nombre = $this->nombre($datos['name'] ?? null);
        $canal = $this->canal($datos['kind'] ?? null);

        $this->exigePerfil($perfilId);
        $this->exigeCodigoLibre($perfilId, $codigo);

        $fila = [
            'fiscal_profile_id' => $perfilId,
            'code' => $codigo,
            'name' => $nombre,
            'kind' => $canal,
            'base_url' => $this->texto($datos['base_url'] ?? null, self::DIRECCION_MAXIMA),
            'auth_scheme' => $this->texto($datos['auth_scheme'] ?? null, self::AUTENTICACION_MAXIMA),
            'credentials_ref' => $this->texto($datos['credentials_ref'] ?? null, self::REFERENCIA_MAXIMA),
            // El PREFIJO, nunca el secreto: el secreto vive en el entorno.
            'credential_env_prefix' => $this->texto($datos['credential_env_prefix'] ?? null, self::PREFIJO_MAXIMO),
            'is_active' => (bool) ($datos['is_active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) $this->connection->table('fiscal_providers')->insertGetId($fila);
    }

    /**
     * La EDICION de un proveedor: su nombre, su direccion, su referencia, su
     * prefijo y su interruptor.
     *
     * Y LO QUE NO SE EDITA, Y POR QUE: el canal (`kind`) y el codigo. El
     * canal decide como se emite y quien asigna el numero de control, y el
     * codigo es la clave natural del proveedor dentro de su perfil.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws RuntimeException Si el proveedor no existe, o si algo de lo
     *                          declarado no vale.
     */
    public function update(int $providerId, array $datos): void
    {
        $proveedor = $this->connection->table('fiscal_providers')->where('id', $providerId)->first();

        if ($proveedor === null) {
            throw new RuntimeException("El proveedor fiscal {$providerId} no existe.");
        }

        $fila = ['updated_at' => now()];

        // SOLO lo que viene en la carga: una edicion parcial no borra lo que
        // no se ha tocado.
        if (array_key_exists('name', $datos)) {
            $fila['name'] = $this->nombre($datos['name']);
        }

        if (array_key_exists('base_url', $datos)) {
            $fila['base_url'] = $this->texto($datos['base_url'], self::DIRECCION_MAXIMA);
        }

        if (array_key_exists('auth_scheme', $datos)) {
            $fila['auth_scheme'] = $this->texto($datos['auth_scheme'], self::AUTENTICACION_MAXIMA);
        }

        if (array_key_exists('credentials_ref', $datos)) {
            $fila['credentials_ref'] = $this->texto($datos['credentials_ref'], self::REFERENCIA_MAXIMA);
        }

        if (array_key_exists('credential_env_prefix', $datos)) {
            $fila['credential_env_prefix'] = $this->texto($datos['credential_env_prefix'], self::PREFIJO_MAXIMO);
        }

        if (array_key_exists('is_active', $datos) && $datos['is_active'] !== null) {
            $fila['is_active'] = (bool) $datos['is_active'];
        }

        $this->connection->table('fiscal_providers')->where('id', $providerId)->update($fila);
    }

    /**
     * El PERFIL del proveedor tiene que existir: el canal pertenece a un
     * regimen.
     *
     * @throws RuntimeException Si el perfil no existe.
     */
    private function exigePerfil(int $perfilId): void
    {
        if ($perfilId <= 0) {
            throw new RuntimeException(
                'El proveedor fiscal necesita su perfil: un proveedor fiscal pertenece a un regimen.'
            );
        }

        if (! $this->connection->table('fiscal_profiles')->where('id', $perfilId)->exists()) {
            throw new RuntimeException(
                "El perfil fiscal {$perfilId} no existe: un proveedor fiscal pertenece a un regimen."
            );
        }
    }

    /**
     * El CODIGO es la clave natural del proveedor dentro de su perfil.
     *
     * @throws RuntimeException Si el codigo ya existe.
     */
    private function exigeCodigoLibre(int $perfilId, string $codigo): void
    {
        $existe = $this->connection->table('fiscal_providers')
            ->where('fiscal_profile_id', $perfilId)
            ->where('code', $codigo)
            ->exists();

        if ($existe) {
            throw new RuntimeException(
                "El perfil {$perfilId} ya tiene un proveedor con el codigo «{$codigo}»: "
                .'el codigo es la clave natural del proveedor y es unico dentro del perfil.'
            );
        }
    }

    /**
     * El CODIGO del proveedor.
     *
     * @throws RuntimeException Si el codigo no vale.
     */
    private function codigo(mixed $codigo): string
    {
        $valor = $codigo === null ? '' : trim((string) $codigo);

        if ($valor === '') {
            throw new RuntimeException('El proveedor fiscal necesita un codigo: es su clave natural.');
        }

        if (mb_strlen($valor) > self::CODIGO_MAXIMO) {
            throw new RuntimeException(
                'El codigo del proveedor fiscal no puede tener mas de '.self::CODIGO_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * El NOMBRE del proveedor: el texto que ve la persona.
     *
     * @throws RuntimeException Si el nombre no vale.
     */
    private function nombre(mixed $nombre): string
    {
        $valor = $nombre === null ? '' : trim((string) $nombre);

        if ($valor === '') {
            throw new RuntimeException('El proveedor fiscal necesita un nombre.');
        }

        if (mb_strlen($valor) > self::NOMBRE_MAXIMO) {
            throw new RuntimeException(
                'El nombre del proveedor fiscal no puede tener mas de '.self::NOMBRE_MAXIMO
                .' caracteres: es lo que admite la columna.'
            );
        }

        return $valor;
    }

    /**
     * El CANAL del proveedor: el tipo de actor por el que emite.
     *
     * @throws RuntimeException Si el canal no existe.
     */
    private function canal(mixed $canal): string
    {
        $valor = $canal === null ? '' : trim((string) $canal);

        if (ProviderKind::tryFrom($valor) === null) {
            throw new RuntimeException(
                "El canal '{$valor}' no existe: solo se admiten "
                .implode(', ', array_column(ProviderKind::cases(), 'value')).'.'
            );
        }

        return $valor;
    }

    /**
     * Un TEXTO de la declaracion del canal.
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
                "Un dato del proveedor fiscal no puede tener mas de {$tope} caracteres: "
                .'es lo que admite la columna.'
            );
        }

        return $texto;
    }
}
