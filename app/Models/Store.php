<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code_name', 'mobile', 'email', 'country_id', 'zip_code', 'address',
        'logo', 'favicon', 'thumbnail', 'sort_order', 'status',
        // El perfil fiscal DECLARADO por la tienda: anulado, el perfil es el
        // vigente de su pais.
        'fiscal_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'fiscal_profile_id' => 'integer',
            'sort_order' => 'integer',
            'status' => 'boolean',
            'feedback_at' => 'datetime',
        ];
    }

    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    /**
     * El perfil fiscal DECLARADO por la tienda.
     *
     * Es `null` en la tienda que no declara ninguno: esa usa el perfil
     * vigente de su pais, y el que lo resuelve es
     * `StoreFiscalProfileResolver`.
     */
    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class, 'fiscal_profile_id');
    }

    public function fiscalRegistrations(): HasMany
    {
        return $this->hasMany(StoreFiscalRegistration::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_store_scopes')->withTimestamps();
    }
}
