<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EmailCampaign extends Model
{
    protected $fillable = ['email_template_id', 'lead_list_id', 'campaign_audience_group_id', 'created_by', 'name', 'audience_type', 'status', 'scheduled_at', 'started_at', 'completed_at', 'recipient_count', 'sent_count', 'failed_count', 'skipped_count', 'failure_reason', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'];

    protected $hidden = ['attachment_path'];

    protected static function booted(): void
    {
        static::deleted(function (EmailCampaign $campaign) {
            if ($campaign->attachment_path) {
                Storage::disk('local')->delete($campaign->attachment_path);
            }
        });
    }

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function leadList()
    {
        return $this->belongsTo(LeadList::class);
    }

    public function audienceGroup()
    {
        return $this->belongsTo(CampaignAudienceGroup::class, 'campaign_audience_group_id');
    }

    public function recipients()
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }
}
