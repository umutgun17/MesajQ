<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'name',
        'surname',
        'email',
        'phone',
        'platform_id',
        'platform_type',
        'platform_customer_id',
        'avatar',
        'gender',
        'birth_date',
        'city',
        'district',
        'address',
        'member_type',
        'is_active',
        'mail_consent',
        'sms_consent',
        'total_orders',
        'total_spent',
        'loyalty_points',
        'platform_created_at',
        'last_login_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'gender' => 'integer',
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'mail_consent' => 'boolean',
            'sms_consent' => 'boolean',
            'platform_created_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ── Accessors ──

    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . ($this->surname ?? ''));
    }

    // ── Scopes ──

    public function scopeForChannel($query, int $channelId)
    {
        return $query->where('channel_id', $channelId);
    }

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
