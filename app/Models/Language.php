<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'code'];

    protected function casts(): array
    {
        return [
            'name' => 'string',
            'slug' => 'string',
            'code' => 'string',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LanguageTranslation::class, 'lang_id');
    }
}
