<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['name', 'slug', 'monthly_price_cents', 'yearly_price_cents', 'limits', 'is_active', 'stripe_monthly_price_id', 'stripe_yearly_price_id'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'is_active' => 'boolean'];
    }
}
