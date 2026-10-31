<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id', 'timezone', 'base_color', 'reference_format', 'sales_reference_prefix',
        'invoice_view', 'invoice_footer_text', 'invoice_edit_lifespan', 'invoice_edit_lifespan_unit',
        'invoice_delete_lifespan', 'invoice_delete_lifespan_unit', 'auto_print', 'remote_printing',
        'char_per_line', 'sound_effect', 'expiry_alert_enabled',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'invoice_edit_lifespan' => 'integer',
            'invoice_delete_lifespan' => 'integer',
            'char_per_line' => 'integer',
            'auto_print' => 'boolean',
            'remote_printing' => 'boolean',
            'sound_effect' => 'boolean',
            'expiry_alert_enabled' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
