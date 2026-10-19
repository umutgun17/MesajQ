<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    public const OPEN = 'open';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const VOID = 'void';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'number', 'status', 'description', 'subtotal', 'tax_rate',
        'tax_amount', 'total', 'currency', 'period_start', 'period_end', 'due_at', 'paid_at',
        'gateway', 'gateway_payment_id', 'gateway_token', 'failure_reason', 'attempts',
        'billing_snapshot', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'billing_snapshot' => 'array',
            'meta' => 'array',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Sıralı fatura numarası: {PREFIX}-{YIL}-{000001}. Yarış koşulu için satır kilidi. */
    public static function nextNumber(): string
    {
        $prefix = config('saas.billing.invoice_prefix', 'INV');
        $year = now()->format('Y');

        return DB::transaction(function () use ($prefix, $year) {
            $last = static::where('number', 'like', "{$prefix}-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('number');
            $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

            return sprintf('%s-%s-%06d', $prefix, $year, $seq);
        });
    }

    public function toPanelArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'description' => $this->description,
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'period_start' => $this->period_start?->toISOString(),
            'period_end' => $this->period_end?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'gateway' => $this->gateway,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
