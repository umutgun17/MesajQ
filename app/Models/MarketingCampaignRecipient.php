<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MarketingCampaignRecipient extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'coupon_used' => 'boolean',
        'sent_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function message()
    {
        return $this->belongsTo(MarketingMessage::class, 'message_id');
    }
}
