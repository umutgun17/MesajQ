<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MarketingList extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function contacts()
    {
        return $this->hasMany(MarketingListContact::class, 'list_id');
    }
}
