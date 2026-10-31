<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Business audit trail. Its table (`audit_logs`) is created by M26. */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'store_id', 'action', 'entity_type', 'entity_id', 'context', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'store_id' => 'integer',
            'action' => 'string',
            'entity_type' => 'string',
            'entity_id' => 'integer',
            'ip_address' => 'string',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
