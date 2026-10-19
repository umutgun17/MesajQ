<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'template_params' => 'array',
        'url_button' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function list()
    {
        return $this->belongsTo(MarketingList::class, 'list_id');
    }

    public function recipients()
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'campaign_id');
    }
}
