<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAudienceGroup extends Model
{
    protected $fillable = ['name', 'countries', 'require_email'];
    protected function casts(): array { return ['countries' => 'array', 'require_email' => 'boolean']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
    public function campaigns() { return $this->hasMany(EmailCampaign::class); }

    public function leadsQuery()
    {
        return $this->workspace->leads()->whereIn('country', $this->countries ?: [])
            ->when($this->require_email, fn ($query) => $query->whereNotNull('email')->where('email', '!=', ''));
    }
}
