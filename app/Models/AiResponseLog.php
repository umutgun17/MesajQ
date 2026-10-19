<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResponseLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'comment_id',
        'user_id',
        'prompt',
        'ai_response',
        'final_response',
        'was_approved',
        'was_edited',
        'response_time_ms',
        'model_used',
        'training_data_ids',
        'confidence_score',
        'detected_intent',
        'extracted_entities',
    ];

    protected function casts(): array
    {
        return [
            'was_approved' => 'boolean',
            'was_edited' => 'boolean',
            'response_time_ms' => 'integer',
            'training_data_ids' => 'array',
            'confidence_score' => 'float',
            'detected_intent' => 'array',
            'extracted_entities' => 'array',
        ];
    }

    // ── Relationships ──

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
