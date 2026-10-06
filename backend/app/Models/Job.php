<?php

namespace App\Models;

use App\Support\RecruitmentEmail;
use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'workspace_jobs';

    protected $guarded = ['id', 'workspace_id'];
    protected $appends = ['trust_score', 'risk_level', 'risk_reasons', 'opportunity_score'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
            'scraped_at' => 'datetime',
            'enriched_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeHasCampaignEmail($query)
    {
        return $query->whereNotNull('contact_email')->where('contact_email', '!=', '')
            ->whereIn('email_discovery_status', RecruitmentEmail::eligibleStatuses());
    }

    private function assessment(): array
    {
        $source = strtolower($this->source_platform ?? '');
        $text = strtolower(strip_tags(($this->title ?? '').' '.($this->description ?? '')));
        $trustedSources = ['linkedin', 'indeed', 'glassdoor', 'google', 'ziprecruiter', 'zip recruiter', 'himalayas', 'remotelanders', 'bayt', 'naukri'];
        $trust = 25;
        $trust += in_array($source, $trustedSources, true) ? 20 : 5;
        $trust += str_starts_with((string) $this->source_url, 'https://') ? 10 : 0;
        $trust += filled($this->company_name) ? 10 : 0;
        $trust += filled($this->company_domain) || filled($this->company_website) ? 15 : 0;
        $trust += filled($this->contact_email) ? 10 : 0;
        $trust += strlen((string) $this->description) >= 400 ? 10 : 0;
        $trust += $this->posted_at ? 5 : 0;
        $reasons = [];
        foreach ([
            'upfront payment' => 'Requests upfront payment', 'registration fee' => 'Mentions a registration fee',
            'telegram' => 'Moves applicants to Telegram', 'whatsapp only' => 'Uses WhatsApp-only contact',
            'cryptocurrency' => 'Mentions cryptocurrency payment', 'buy equipment' => 'Asks applicants to buy equipment',
        ] as $pattern => $reason) if (str_contains($text, $pattern)) { $trust -= 20; $reasons[] = $reason; }
        if ($this->contact_email && preg_match('/@(gmail|yahoo|hotmail|outlook)\./i', $this->contact_email)) {
            $trust -= 10; $reasons[] = 'Contact uses a public email provider';
        }
        if (! $this->company_name) $reasons[] = 'Company name is missing';
        if (! $this->source_url) $reasons[] = 'Original listing link is missing';
        $trust = max(0, min(100, $trust));

        $opportunity = 15;
        $opportunity += $this->workplace_type === 'remote' ? 15 : 5;
        $opportunity += filled($this->contact_email) ? 15 : 0;
        $opportunity += strlen((string) $this->description) >= 400 ? 15 : 5;
        $opportunity += ($this->salary_min || $this->salary_max) ? 10 : 0;
        $opportunity += (filled($this->company_domain) || filled($this->company_website)) ? 10 : 0;
        $opportunity += in_array($source, $trustedSources, true) ? 10 : 0;
        if ($this->posted_at) $opportunity += $this->posted_at->gte(now()->subDays(3)) ? 20 : ($this->posted_at->gte(now()->subDays(14)) ? 10 : 0);

        return ['trust' => $trust, 'risk' => $trust >= 70 ? 'low' : ($trust >= 45 ? 'review' : 'high'),
            'reasons' => $reasons ?: ['No common scam signals detected'], 'opportunity' => max(0, min(100, $opportunity))];
    }

    public function getTrustScoreAttribute(): int { return $this->assessment()['trust']; }
    public function getRiskLevelAttribute(): string { return $this->assessment()['risk']; }
    public function getRiskReasonsAttribute(): array { return $this->assessment()['reasons']; }
    public function getOpportunityScoreAttribute(): int { return $this->assessment()['opportunity']; }
}
