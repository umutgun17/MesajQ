<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SearchSynonym extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'term',
        'synonyms',
        'type',
        'source',
        'status',
        'hits',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'meta' => 'array',
            'hits' => 'integer',
        ];
    }
}
