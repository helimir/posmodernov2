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
 *
 * Y `account_code` es la CUENTA CONTABLE en la que se carga el gasto: un
 * DATO del negocio (cada tienda decide en que cuenta carga sus gastos), y no
 * un codigo escrito en el codigo.
 */
class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'account_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'name' => 'string',
            'account_code' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }
}
