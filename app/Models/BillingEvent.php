<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingEvent extends Model
{
    protected $fillable = ['tenant_id', 'type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public static function record(?int $tenantId, string $type, array $payload = []): void
    {
        static::create(['tenant_id' => $tenantId, 'type' => $type, 'payload' => $payload]);
    }
}
