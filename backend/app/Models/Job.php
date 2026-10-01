<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'workspace_jobs';

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
            'scraped_at' => 'datetime',
            'enriched_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
