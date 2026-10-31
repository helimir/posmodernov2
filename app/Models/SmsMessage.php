<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbox row: a message queued for delivery. The delivery job lands in Phase 2.
 */
class SmsMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id', 'sms_provider_id', 'message_template_id', 'mobile_number', 'recipient_name',
        'body', 'status', 'scheduled_at', 'sent_at', 'attempts', 'response',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'sms_provider_id' => 'integer',
            'message_template_id' => 'integer',
            'mobile_number' => 'string',
            'recipient_name' => 'string',
            'body' => 'string',
            'status' => 'string',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
            'response' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SmsProvider::class, 'sms_provider_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
