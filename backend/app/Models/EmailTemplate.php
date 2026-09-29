<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['name', 'subject', 'html_body', 'text_body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaigns()
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
