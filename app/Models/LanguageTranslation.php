<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanguageTranslation extends Model
{
    use HasFactory;

    protected $fillable = ['lang_id', 'lang_key', 'lang_value'];

    protected function casts(): array
    {
        return [
            'lang_id' => 'integer',
            'lang_key' => 'string',
            'lang_value' => 'string',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'lang_id');
    }
}
