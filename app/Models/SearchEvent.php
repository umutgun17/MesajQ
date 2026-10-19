<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'search_query_id',
        'product_id',
        'platform_product_id',
        'type',
        'position',
        'value',
        'platform_order_id',
        'session_id',
        'customer_id',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'value' => 'decimal:2',
            'is_test' => 'boolean',
        ];
    }

    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(SearchQuery::class, 'search_query_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
