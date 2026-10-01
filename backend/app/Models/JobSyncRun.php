<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobSyncRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sources' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
