<?php

namespace App\Models;

use App\Enums\ReturnStaffRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'avatar',
        'last_login_at',
        'can_use_ai',
        'extension',
        'return_role',
        'page_permissions',
        'is_owner',
        'terms_accepted_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'can_use_ai' => 'boolean',
            'return_role' => ReturnStaffRole::class,
            'page_permissions' => 'array',
            'is_owner' => 'boolean',
            'terms_accepted_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_to');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function commentReplies(): HasMany
    {
        return $this->hasMany(CommentReply::class);
    }

    public function createdKnowledgeBase(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class, 'created_by');
    }

    public function aiResponseLogs(): HasMany
    {
        return $this->hasMany(AiResponseLog::class);
    }

    // ── Helpers ──

    public function isSuperAdmin(): bool
    {
        return is_null($this->tenant_id);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Temsilcinin ürün→sayfa yetki matrisi (page_permissions sütunu).
     * null (hiç atanmamış) → eski davranış: Mesaj ürününün operasyonel sayfaları.
     * Bilinmeyen ürün/sayfa anahtarları sessizce ayıklanır.
     */
    public function pagePermissionMatrix(): array
    {
        $raw = is_array($this->page_permissions)
            ? $this->page_permissions
            : ['inbox' => ['messages', 'comments', 'email_archive']];

        $matrix = [];
        foreach ($raw as $product => $pages) {
            $valid = array_values(array_intersect((array) $pages, Tenant::PRODUCT_PAGES[$product] ?? []));
            if ($valid) {
                $matrix[$product] = $valid;
            }
        }

        return $matrix;
    }

    /**
     * Kullanıcının erişebildiği ürünler (7 alt ürün ekseni).
     * Admin → tenant'ın tüm ürünleri; agent → yetki matrisindeki ürünler
     * (tenant'ın sahip olduklarıyla kesiştirilir).
     */
    public function getAllowedProducts(): array
    {
        if ($this->isSuperAdmin()) {
            return Tenant::PRODUCTS;
        }

        $tenantProducts = $this->tenant?->products() ?? [];

        if ($this->role === 'agent') {
            return array_values(array_intersect(array_keys($this->pagePermissionMatrix()), $tenantProducts));
        }

        return $tenantProducts;
    }

    /**
     * Sayfa yetkileri ürün listesinden TÜRETİLİR (Tenant::PRODUCT_PAGES).
     * Admin: ürünlerin tüm sayfaları + 'settings'.
     * Agent: yetki matrisinde seçili sayfalar (ayar sayfaları hiçbir zaman;
     * analiz/panel sayfaları ancak matriste seçiliyse).
     */
    public function getAllowedPages(): array
    {
        if ($this->role === 'agent' && ! $this->isSuperAdmin()) {
            $allowedProducts = $this->getAllowedProducts();
            $pages = [];
            foreach ($this->pagePermissionMatrix() as $product => $productPages) {
                if (in_array($product, $allowedProducts, true)) {
                    $pages = array_merge($pages, $productPages);
                }
            }
            $pages = array_values(array_unique($pages));
        } else {
            $pages = [];
            foreach ($this->getAllowedProducts() as $product) {
                $pages = array_merge($pages, Tenant::PRODUCT_PAGES[$product] ?? []);
            }
            $pages = array_values(array_unique($pages));
            // Ürün ayar sayfaları (kanallar, iade kuralları, GA4 vs.) admin'e açık
            $pages[] = 'settings';
        }

        // İade personeli/yöneticisi, diğer izinlerinden bağımsız olarak 'returns' sayfasını görür.
        if ($this->returnRole() !== ReturnStaffRole::None && ! in_array('returns', $pages, true)) {
            $pages[] = 'returns';
        }

        return $pages;
    }

    public function hasPermission(string $page): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($page, $this->getAllowedPages());
    }

    public function canUseAi(): bool
    {
        return (bool) $this->can_use_ai;
    }

    // ── İade modülü yetki yardımcıları (görev ayrılığı) ──

    public function returnRole(): ReturnStaffRole
    {
        return $this->return_role instanceof ReturnStaffRole
            ? $this->return_role
            : (ReturnStaffRole::tryFrom((string) $this->return_role) ?? ReturnStaffRole::None);
    }

    /**
     * İade talebini 1. seviye (personel) onaylayabilir mi?
     * Aynı talepte staff+manager onayının aynı kişi olamayacağı kuralı işlem katmanında ayrıca uygulanır.
     */
    public function canStaffApproveReturns(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin() || $this->returnRole()->canStaffApprove();
    }

    /**
     * İade talebini 2. seviye (yönetici) onaylayabilir mi? (para iadesini tetikler)
     */
    public function canManagerApproveReturns(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin() || $this->returnRole()->canManagerApprove();
    }

    /**
     * İade talebine personel notu ekleyebilir mi?
     * Not eklemek talebin durumunu DEĞİŞTİRMEZ ve müşteriye bildirim gitmez; sadece iç kayıttır.
     * Bu yüzden iade ekranını görebilen herkese (salt görüntüleyen personel dâhil) açıktır.
     */
    public function canAddReturnNotes(): bool
    {
        return $this->hasPermission('returns');
    }
}
