<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReply extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'comment_id',
        'user_id',
        'content',
        'is_ai_generated',
        'ai_original_content',
        'platform_reply_id',
    ];

    protected function casts(): array
    {
        return [
            'is_ai_generated' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
