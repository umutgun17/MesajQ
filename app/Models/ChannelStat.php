<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelStat extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'date',
        'total_conversations',
        'answered',
        'unanswered',
        'avg_response_time_seconds',
        'ai_used_count',
        'ai_approved_count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_conversations' => 'integer',
            'answered' => 'integer',
            'unanswered' => 'integer',
            'avg_response_time_seconds' => 'integer',
            'ai_used_count' => 'integer',
            'ai_approved_count' => 'integer',
        ];
    }

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
