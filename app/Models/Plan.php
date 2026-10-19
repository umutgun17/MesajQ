<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'family', 'products', 'limits', 'features',
        'price_monthly', 'price_yearly', 'currency', 'trial_days', 'is_public',
        'is_trial_default', 'highlight', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'limits' => 'array',
            'features' => 'array',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'is_public' => 'boolean',
            'is_trial_default' => 'boolean',
            'highlight' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Yıllık fiyat: açıkça verilmemişse aylık × 12 × (1 − yıllık indirim). */
    public function yearlyPrice(): float
    {
        if ($this->price_yearly !== null) {
            return (float) $this->price_yearly;
        }
        $discount = (float) config('saas.billing.yearly_discount', 0.25);

        return round((float) $this->price_monthly * 12 * (1 - $discount), 2);
    }

    public function priceFor(string $cycle): float
    {
        return $cycle === 'yearly' ? $this->yearlyPrice() : (float) $this->price_monthly;
    }

    public function limit(string $metric): ?int
    {
        $v = $this->limits[$metric] ?? null;

        return $v === null ? null : (int) $v;
    }

    public static function trialDefault(): ?self
    {
        return static::where('family', config('saas.family'))->where('is_trial_default', true)->first()
            ?? static::where('family', config('saas.family'))->where('is_public', true)->orderBy('sort_order')->first();
    }

    public function toPublicArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'products' => $this->products,
            'limits' => $this->limits,
            'features' => $this->features,
            'price_monthly' => (float) $this->price_monthly,
            'price_yearly' => $this->yearlyPrice(),
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,
            'highlight' => $this->highlight,
        ];
    }
}
