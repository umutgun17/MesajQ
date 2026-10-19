<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingProfile extends Model
{
    protected $fillable = [
        'tenant_id', 'type', 'legal_name', 'tax_office', 'tax_number', 'email', 'phone',
        'address', 'district', 'city', 'country', 'postal_code',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isComplete(): bool
    {
        return filled($this->legal_name) && filled($this->address) && filled($this->city)
            && ($this->type === 'individual' || filled($this->tax_number));
    }
}
