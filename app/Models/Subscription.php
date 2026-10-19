<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    public const TRIALING = 'trialing';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const CANCELED = 'canceled';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_cycle', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancel_at_period_end', 'canceled_at',
        'ends_at', 'gateway', 'gateway_customer_ref', 'gateway_card_ref', 'card_meta',
        'price', 'currency', 'failed_attempts', 'last_payment_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_payment_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'card_meta' => 'array',
            'meta' => 'array',
            'price' => 'decimal:2',
            'gateway_card_ref' => 'encrypted',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Panel kullanımına izin var mı? (deneme, aktif, ödeme-bekliyor tolerans penceresi, dönem sonuna kadar iptal) */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            self::TRIALING => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            self::ACTIVE => true,
            self::PAST_DUE => $this->graceEndsAt()?->isFuture() ?? false,
            self::CANCELED => $this->ends_at === null || $this->ends_at->isFuture(),
            default => false,
        };
    }

    public function graceEndsAt(): ?CarbonInterface
    {
        if (! $this->current_period_end) {
            return null;
        }

        return $this->current_period_end->copy()->addDays((int) config('saas.billing.grace_days', 7));
    }

    public function hasStoredCard(): bool
    {
        return $this->gateway === 'iyzico' && ! empty($this->gateway_customer_ref) && ! empty($this->gateway_card_ref);
    }

    public function isTrialing(): bool
    {
        return $this->status === self::TRIALING;
    }

    public function daysLeft(): ?int
    {
        $end = $this->status === self::TRIALING ? $this->trial_ends_at : ($this->status === self::CANCELED ? $this->ends_at : $this->current_period_end);

        return $end ? max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false)) : null;
    }

    public function toPanelArray(): array
    {
        return [
            'status' => $this->status,
            'grants_access' => $this->grantsAccess(),
            'billing_cycle' => $this->billing_cycle,
            'plan' => $this->plan?->toPublicArray(),
            'trial_ends_at' => $this->trial_ends_at?->toISOString(),
            'current_period_start' => $this->current_period_start?->toISOString(),
            'current_period_end' => $this->current_period_end?->toISOString(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'ends_at' => $this->ends_at?->toISOString(),
            'grace_ends_at' => $this->status === self::PAST_DUE ? $this->graceEndsAt()?->toISOString() : null,
            'days_left' => $this->daysLeft(),
            'gateway' => $this->gateway,
            'has_card' => $this->hasStoredCard(),
            'card' => $this->card_meta ? [
                'last4' => $this->card_meta['last4'] ?? null,
                'association' => $this->card_meta['association'] ?? null,
                'bank' => $this->card_meta['bank'] ?? null,
            ] : null,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'last_payment_at' => $this->last_payment_at?->toISOString(),
        ];
    }
}
