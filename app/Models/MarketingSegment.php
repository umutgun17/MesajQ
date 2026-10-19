<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSegment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'definition' => 'array',
        'is_system' => 'boolean',
    ];
}
