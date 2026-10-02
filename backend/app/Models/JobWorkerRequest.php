<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobWorkerRequest extends Model
{
    protected $fillable = ['workspace_id', 'requested_by', 'type', 'status', 'parameters', 'result', 'error', 'started_at', 'finished_at'];
    protected function casts(): array { return ['parameters' => 'array', 'result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
}
