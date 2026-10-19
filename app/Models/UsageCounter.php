<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    protected $fillable = ['tenant_id', 'metric', 'period', 'value'];
}
