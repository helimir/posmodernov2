<?php

namespace App\Models;

use App\Support\Pos\StoreScope;
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
        ];
    }

    /**
     * El ALCANCE POR DEFECTO de las ventas.
     *
     * REGLA que fija este metodo: *una venta de otra tienda no se puede
     * leer*. El filtro NO es un `where` de cada pantalla: es el alcance de la
     * propia tabla, y por eso la lista y la ficha no pueden separarse, y una
     * pantalla nueva no puede olvidarlo.
     *
     * Y EL ALCANCE ES EL DEL ROL: el de `PosContext::scopeIds()`. Un rol sin
     * ambito (el superusuario, el contador) ve todas las tiendas, y un rol
     * con ambito ve las suyas y solo las suyas.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new StoreScope);
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
