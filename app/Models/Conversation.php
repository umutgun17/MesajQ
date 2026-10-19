<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'customer_id',
        'type',
        'status',
        'is_urgent',
        'is_read',
        'unread_count',
        'last_message_at',
        'assigned_to',
        'platform_conversation_id',
        'metadata',
        'ai_suggestion',
        'ai_suggestion_confidence',
    ];

    protected function casts(): array
    {
        return [
            'is_urgent' => 'boolean',
            'is_read' => 'boolean',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
            'ai_suggestion_confidence' => 'float',
        ];
    }

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function aiResponseLogs(): HasMany
    {
        return $this->hasMany(AiResponseLog::class);
    }
}
