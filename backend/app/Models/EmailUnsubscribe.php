<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailUnsubscribe extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }
}
