<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir müşterinin/siparişin çok adımlı bir pazarlama otomasyonuna kaydı (enrollment).
 * İlk kullanım: sepet kurtarma (automation='cart_recovery').
 */
class MarketingJourney extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'automation',
        'order_id',
        'customer_id',
        'recipient_email',
        'recipient_phone',
        'status',
        'current_step',
        'stopped_reason',
        'entered_at',
        'next_action_at',
        'last_sent_at',
        'converted_at',
        'revenue',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'revenue' => 'decimal:2',
            'meta' => 'array',
            'entered_at' => 'datetime',
            'next_action_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
