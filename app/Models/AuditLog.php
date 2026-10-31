<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Business audit trail. Its table (`audit_logs`) is created by M26, in the PR that lands the i18n and outbox tables. */
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
            'entity_id' => 'integer',
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
