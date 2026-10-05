<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAudienceGroup extends Model
{
    protected $fillable = ['name', 'countries', 'require_email'];
    protected function casts(): array { return ['countries' => 'array', 'require_email' => 'boolean']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
    public function campaigns() { return $this->hasMany(EmailCampaign::class); }

    public function jobsQuery()
    {
        return self::eligibleJobs($this->workspace)->whereIn('country', $this->countries ?: []);
    }

    public static function eligibleJobs(Workspace $workspace)
    {
        $alreadyEmailed = EmailCampaignRecipient::query()->select('email')->where('status', 'sent')
            ->whereHas('campaign', fn ($query) => $query->where('workspace_id', $workspace->id));
        $suppressed = EmailSuppression::query()->select('email')->where('workspace_id', $workspace->id);

        return $workspace->jobs()->where('status', '!=', 'applied')->whereNotNull('contact_email')
            ->where('contact_email', '!=', '')->whereNotIn('contact_email', $alreadyEmailed)->whereNotIn('contact_email', $suppressed);
    }
}
