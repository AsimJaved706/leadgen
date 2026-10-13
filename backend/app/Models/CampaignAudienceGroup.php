<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAudienceGroup extends Model
{
    protected $fillable = ['name', 'countries', 'tags', 'require_email'];
    protected function casts(): array { return ['countries' => 'array', 'tags' => 'array', 'require_email' => 'boolean']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
    public function campaigns() { return $this->hasMany(EmailCampaign::class); }

    public function jobsQuery()
    {
        $query = self::eligibleJobs($this->workspace);
        $countries = $this->countries ?: [];
        if (! in_array('__all__', $countries, true)) {
            $query->where(function ($countryQuery) use ($countries) {
                $known = array_values(array_filter($countries, fn ($country) => $country !== '__unknown__'));
                if ($known) $countryQuery->whereIn('country', $known);
                if (in_array('__unknown__', $countries, true)) {
                    $method = $known ? 'orWhere' : 'where';
                    $countryQuery->{$method}(fn ($unknown) => $unknown->whereNull('country')->orWhere('country', ''));
                }
            });
        }
        if ($this->tags) {
            $query->where(function ($tagQuery) {
                foreach ($this->tags as $tag) {
                    $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tag).'%';
                    $tagQuery->orWhere('title', 'like', $term)->orWhere('description', 'like', $term)
                        ->orWhere('requirements', 'like', $term);
                }
            });
        }
        return $query;
    }

    public static function eligibleJobs(Workspace $workspace)
    {
        $alreadyEmailed = EmailCampaignRecipient::query()->select('email')->where('status', 'sent')
            ->whereHas('campaign', fn ($query) => $query->where('workspace_id', $workspace->id));
        $suppressed = EmailSuppression::query()->select('email')->where('workspace_id', $workspace->id);

        return $workspace->jobs()->where('status', '!=', 'applied')->hasCampaignEmail()
            ->whereNotIn('contact_email', $alreadyEmailed)->whereNotIn('contact_email', $suppressed);
    }
}
