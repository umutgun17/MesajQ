<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFavorite extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'customer_id',
        'product_id',
        'platform_favorite_id',
        'platform_customer_id',
        'platform_product_id',
        'product_name',
        'product_sku',
        'product_image',
        'product_url',
        'product_price',
        'product_price_tax',
        'currency',
        'stock_quantity',
        'variant_count',
        'favorited_at',
    ];

    protected function casts(): array
    {
        return [
            'favorited_at' => 'datetime',
            'product_price' => 'decimal:4',
            'product_price_tax' => 'decimal:4',
            'stock_quantity' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
