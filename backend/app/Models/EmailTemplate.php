<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EmailTemplate extends Model
{
    protected $fillable = ['name', 'subject', 'html_body', 'text_body', 'is_active', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'];

    protected $hidden = ['attachment_path'];

    protected static function booted(): void
    {
        static::deleted(function (EmailTemplate $template) {
            if ($template->attachment_path) Storage::disk('local')->delete($template->attachment_path);
        });
    }

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
