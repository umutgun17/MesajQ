<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'post_title',
        'post_url',
        'platform_comment_id',
        'platform_post_id',
        'content',
        'status',
    ];

    // ── Relationships ──

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommentReply::class);
    }

    public function aiResponseLogs(): HasMany
    {
        return $this->hasMany(AiResponseLog::class);
    }
}
