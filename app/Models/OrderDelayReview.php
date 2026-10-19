<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelayReview extends Model
{
    use BelongsToTenant;

    /** İnceleme durumları — sıra panelde gösterim sırasıdır. */
    public const STATES = ['new', 'reviewing', 'customer_informed', 'waiting_supply', 'resolved'];

    protected $fillable = [
        'tenant_id',
        'order_id',
        'state',
        'note',
        'assigned_user_id',
        'follow_up_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_at' => 'date:Y-m-d',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
