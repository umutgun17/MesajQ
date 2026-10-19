<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'sender_type',
        'sender_id',
        'content',
        'is_ai_generated',
        'ai_original_content',
        'handling_time_seconds',
        'penalty_points',
        'penalized_by',
        'platform_message_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_ai_generated' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // ── Relationships ──

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function penalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penalized_by');
    }
}
