<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'type'];

    protected function casts(): array
    {
        return [
            'key' => 'string',
            'value' => 'string',
            'type' => 'string',
        ];
    }

    /** The stored value read with the type declared in the `type` column. */
    public function typedValue(): bool|int|string|BigDecimal|null
    {
        return match ($this->type) {
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'int' => $this->value === null ? null : (int) $this->value,
            'decimal' => $this->value === null ? null : BigDecimal::of($this->value),
            default => $this->value,
        };
    }
}
