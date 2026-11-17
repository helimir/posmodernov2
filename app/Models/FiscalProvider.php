<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\ProviderKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Env;
use InvalidArgumentException;

class FiscalProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'code', 'name', 'kind', 'base_url', 'auth_scheme',
        'credentials_ref', 'credential_env_prefix', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'kind' => 'string',
            'credential_env_prefix' => 'string',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            if (ProviderKind::tryFrom((string) $provider->kind) === null) {
                throw new InvalidArgumentException(
                    'kind must be one of: '.implode(', ', array_column(ProviderKind::cases(), 'value'))
                );
            }
        });
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(FiscalProfileEnvironment::class);
    }

    /**
     * El NOMBRE de la variable de entorno donde vive el secreto.
     *
     * Es el PREFIJO que declara la fila, guion bajo, y el nombre de la
     * variable en mayusculas: `PREFIX` + `_` + `VARIABLE`. Es el MISMO
     * formato que usa `sms_providers`, y vive AQUI, en un solo sitio, para
     * que el mensaje que nombra la variable no pueda mentir sobre ella.
     *
     * Es `null` cuando la fila NO declara prefijo: no hay ninguna variable
     * que resolver.
     */
    public function credentialVariable(string $variable): ?string
    {
        $prefijo = $this->credential_env_prefix;

        if ($prefijo === null || trim($prefijo) === '') {
            return null;
        }

        return $prefijo.'_'.strtoupper($variable);
    }

    /**
     * El SECRETO del proveedor, resuelto del entorno en RUNTIME.
     *
     * La fila guarda la REFERENCIA al secreto (el prefijo), nunca el secreto:
     * un secreto en la base de datos es un secreto copiado.
     *
     * Y un secreto AUSENTE no es un error: se devuelve `null`, y lo dice
     * quien necesita la credencial. Un canal local no tiene credenciales, y
     * su prefijo en `null` es una configuracion legitima.
     */
    public function credential(string $variable): ?string
    {
        $nombre = $this->credentialVariable($variable);

        if ($nombre === null) {
            return null;
        }

        $valor = Env::get($nombre);

        return $valor === null ? null : (string) $valor;
    }
}
