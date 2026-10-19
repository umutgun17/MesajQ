<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'category',
        'is_active',
        'credentials',
        'settings',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ChannelStat::class);
    }
}
