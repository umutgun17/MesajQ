<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchQuery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'customer_id',
        'session_id',
        'raw_query',
        'normalized_query',
        'corrected_query',
        'parsed_facets',
        'intent',
        'results_count',
        'has_results',
        'source',
        'device',
        'ip_address',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'parsed_facets' => 'array',
            'results_count' => 'integer',
            'has_results' => 'boolean',
            'is_test' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(SearchEvent::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
