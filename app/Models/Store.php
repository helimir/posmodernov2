<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code_name', 'mobile', 'email', 'country_id', 'zip_code', 'address',
        'logo', 'favicon', 'thumbnail', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'sort_order' => 'integer',
            'status' => 'boolean',
            'feedback_at' => 'datetime',
        ];
    }

    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
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
