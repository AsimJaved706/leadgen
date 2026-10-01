<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $fillable = ['name', 'owner_id', 'plan_id'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function lists()
    {
        return $this->hasMany(LeadList::class);
    }

    public function jobs()
    {
        return $this->hasMany(Job::class);
    }

    public function jobSyncRuns()
    {
        return $this->hasMany(JobSyncRun::class);
    }

    public function emailSetting()
    {
        return $this->hasOne(EmailSetting::class);
    }

    public function emailTemplates()
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailCampaigns()
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
