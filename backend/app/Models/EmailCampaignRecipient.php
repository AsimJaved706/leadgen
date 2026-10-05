<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignRecipient extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
