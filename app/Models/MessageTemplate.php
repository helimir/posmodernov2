<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The catalog of message placeholders (`{{…}}`) available to a message body.
 */
class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'tag_name'];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'tag_name' => 'string',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'message_template_id');
    }
}
