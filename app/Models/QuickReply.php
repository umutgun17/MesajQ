<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuickReply extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'category',
        'title',
        'content',
        'is_active',
        'is_default',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Chatbot'un onaylı bilgi havuzu 5 dk cache'li (ChatKnowledge::corpus).
     * Personel panelden yanlış bir cevabı düzeltince bot ANINDA yeni metni
     * söylemeli — "kapıda ödeme yok" gibi yanlış bir kayıt beş dakika daha
     * servis edilmesin (8 Eyl 2026).
     */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Chat\ChatKnowledge::forget((int) $m->tenant_id));
        static::deleted(fn (self $m) => \App\Services\Chat\ChatKnowledge::forget((int) $m->tenant_id));
    }
}
