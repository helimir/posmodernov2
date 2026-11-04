<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El catalogo de categorias de gasto.
 *
 * NO lleva los diez flags contables del legacy: la clasificacion contable
 * vive en `ledger_entry_types` y se CONSULTA. Aqui solo queda `is_active`,
 * que es de INTERFAZ (permitir ocultar una categoria), no un flag contable.
 */
class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'name' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }
}
