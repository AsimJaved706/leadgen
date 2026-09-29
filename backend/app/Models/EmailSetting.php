<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $fillable = ['host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name', 'reply_to_email', 'is_active', 'verified_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'is_active' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
