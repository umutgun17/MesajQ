<?php

namespace App\Models\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (! $model->tenant_id && auth()->check()) {
                if (auth()->user()->tenant_id) {
                    $model->tenant_id = auth()->user()->tenant_id;
                } elseif (request()->hasHeader('X-Tenant-Id')) {
                    // Super admin creating resource for a specific tenant
                    $model->tenant_id = (int) request()->header('X-Tenant-Id');
                }
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            if (! auth()->check()) {
                return;
            }

            $user = auth()->user();

            if ($user->tenant_id) {
                // Normal user: always filter by own tenant
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $user->tenant_id);
            } elseif (request()->hasHeader('X-Tenant-Id')) {
                // Super admin with X-Tenant-Id header: filter to selected tenant
                $builder->where($builder->getModel()->getTable() . '.tenant_id', (int) request()->header('X-Tenant-Id'));
            }
            // Super admin without header: see all data (no filter)
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where($this->getTable() . '.tenant_id', $tenantId);
    }
}
