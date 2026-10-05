<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSuppression extends Model
{
    protected $fillable = ['workspace_id', 'email', 'reason', 'details'];
    public function workspace() { return $this->belongsTo(Workspace::class); }
}
