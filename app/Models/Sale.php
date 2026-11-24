<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'customer_id',
        'fiscal_document_id',
        'invoice_no',
        'reference_no',
        'sale_date',
        'status',
        'created_by',
        'void_requested_at',
        'void_request_reason',
        'void_requested_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'status' => 'string',
            'store_id' => 'integer',
            'customer_id' => 'integer',
            'fiscal_document_id' => 'integer',
            'created_by' => 'integer',
            'void_requested_at' => 'datetime',
            'void_requested_by' => 'integer',
        ];
    }

    /**
     * QUIEN PIDIO la anulacion de la venta, si la pidio alguien.
     *
     * Y SI NO SE SABE, EL HUECO SE VE: la relacion devuelve `null` y la
     * pantalla ensena un hueco. No se inventa un valor.
     */
    public function voidRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_requested_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function total(): HasOne
    {
        return $this->hasOne(SaleTotal::class, 'sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
    }
}
