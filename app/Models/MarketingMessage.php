<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'channel',
        'recipient',
        'source',
        'source_type',
        'template_id',
        'subject',
        'body',
        'status',
        'skip_reason',
        'provider',
        'provider_message_id',
        'tracking_token',
        'error',
        'cost',
        'revenue',
        'meta',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'cost' => 'decimal:4',
            'revenue' => 'decimal:2',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
