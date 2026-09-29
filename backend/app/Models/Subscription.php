<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime', 'ends_at' => 'datetime',
            'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean', 'canceled_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
