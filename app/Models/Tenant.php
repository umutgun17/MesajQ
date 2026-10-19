<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    /** Bu ürün ailesinin alt ürünleri — tenant aboneliği (plan) bu eksende yazılır (settings.products). */
    public const PRODUCTS = ['inbox', 'chatbot'];

    /**
     * Ürün → sayfa-yetkisi (permission middleware anahtarları) eşlemesi.
     * Sayfa yetkileri ürünlerden TÜRETİLİR; tek yetki kaynağı ürün listesidir.
     * 'settings' tüm tenant adminlerine ayrıca verilir (ürün ayar sayfaları).
     */
    public const PRODUCT_PAGES = [
        'inbox' => ['dashboard', 'messages', 'comments', 'email_archive', 'reports'],
        'chatbot' => ['chatbot'],
    ];

    /**
     * Tenant'ın sahip olduğu ürünler. settings.products tanımlı değilse
     * geriye-uyumluluk için eski allowed_pages listesinden türetilir;
     * o da yoksa tüm ürünler açık kabul edilir.
     */
    public function products(): array
    {
        $settings = $this->settings ?? [];

        if (isset($settings['products']) && is_array($settings['products'])) {
            return array_values(array_intersect($settings['products'], self::PRODUCTS));
        }

        $legacyPages = $settings['allowed_pages'] ?? null;
        if ($legacyPages === null) {
            return []; // plan uygulanmamış tenant: ürün yok (abonelik kapısı yönlendirir)
        }

        $derived = [];
        foreach (self::PRODUCT_PAGES as $product => $pages) {
            if (array_intersect($pages, $legacyPages)) {
                $derived[] = $product;
            }
        }

        return $derived;
    }

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'onboarded_at' => 'datetime',
        ];
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function billingProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BillingProfile::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ── Relationships ──

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function aiTrainingData(): HasMany
    {
        return $this->hasMany(AiTrainingData::class);
    }

    public function knowledgeBase(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function aiResponseLogs(): HasMany
    {
        return $this->hasMany(AiResponseLog::class);
    }

    public function channelStats(): HasMany
    {
        return $this->hasMany(ChannelStat::class);
    }
}
