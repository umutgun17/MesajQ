<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'platform_variant_id',
        'sku',
        'barcode',
        'price',
        'discounted_price',
        'cost_price',
        'stock_quantity',
        'attributes',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
