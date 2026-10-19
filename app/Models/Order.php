<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'customer_id',
        'platform_order_id',
        'platform_customer_id',
        'order_no',
        'order_code',
        'status',
        'status_label',
        'subtotal',
        'discount_amount',
        'shipping_cost',
        'gift_card_amount',
        'coupon_code',
        'points_discount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'currency',
        'customer_name',
        'customer_email',
        'cargo_company',
        'cargo_tracking_no',
        'cargo_tracking_url',
        'payment_type',
        'payment_status',
        'order_source',
        'billing_address',
        'shipping_address',
        'ip_address',
        'items_count',
        'order_date',
        'status_updated_at',
        'platform_updated_at',
        'payments',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'payments' => 'array',
            'metadata' => 'array',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'gift_card_amount' => 'decimal:2',
            'points_discount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'order_date' => 'datetime',
            'status_updated_at' => 'datetime',
            'platform_updated_at' => 'datetime',
        ];
    }

    /**
     * Ticimax dates are Turkey local time stored without timezone.
     * Prevent Laravel from appending 'Z' (UTC) on JSON serialization.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
