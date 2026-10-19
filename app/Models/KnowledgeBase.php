<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBase extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'knowledge_base';

    protected $fillable = [
        'tenant_id',
        'category',
        'title',
        'content',
        'tags',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
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

    // ── Relationships ──

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
