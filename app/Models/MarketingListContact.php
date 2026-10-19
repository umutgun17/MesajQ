<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MarketingListContact extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function list()
    {
        return $this->belongsTo(MarketingList::class, 'list_id');
    }
}
