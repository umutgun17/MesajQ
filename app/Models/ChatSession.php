<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'session_key',
        'visitor_id',
        'status',
        'customer_id',
        'conversation_id',
        'contact',
        'context',
        'page_url',
        'user_message_count',
        'last_activity_at',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'contact' => 'array',
            'context' => 'array',
            'last_activity_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
