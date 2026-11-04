<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una linea de un asiento contable: una cuenta, un importe y un sentido.
 *
 * Una linea es de UNA cuenta: no puede ser debito y credito a la vez. El
 * esquema lo garantiza con CHECK constraints.
 */
class LedgerLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'ledger_entry_id',
        'account_code',
        'debit',
        'credit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'ledger_entry_id' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }
}
