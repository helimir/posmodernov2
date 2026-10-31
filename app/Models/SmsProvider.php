<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Env;

class SmsProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'credential_env_prefix', 'supports_scheduling', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'name' => 'string',
            'credential_env_prefix' => 'string',
            'supports_scheduling' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Resolve a provider secret from the environment, using the stored prefix as the variable namespace. */
    public function credential(string $variable): ?string
    {
        $value = Env::get($this->credential_env_prefix.'_'.strtoupper($variable));

        return $value === null ? null : (string) $value;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'sms_provider_id');
    }
}
