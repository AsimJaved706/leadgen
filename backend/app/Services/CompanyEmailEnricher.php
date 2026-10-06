<?php

namespace App\Services;

use App\Models\Workspace;
use App\Support\RecruitmentEmail;
use Illuminate\Support\Facades\Http;

class CompanyEmailEnricher
{
    public function enrich(Workspace $workspace, int $limit = 25): array
    {
        $checked = $found = 0;
        $jobs = $workspace->jobs()->where(fn ($query) => $query->whereNull('contact_email')->orWhereNotIn('email_discovery_status', RecruitmentEmail::eligibleStatuses()))->whereNotNull('company_website')
            ->where('company_website', '!=', '')->orderBy('enriched_at')->limit($limit)->get();
        foreach ($jobs as $job) {
            $checked++;
            $result = $this->find($job->company_website);
            $job->update([
                'contact_email' => $result['email'],
                'email_discovery_status' => $result['email'] ? 'recruitment_page' : 'not_found',
                'email_source' => $result['url'], 'enriched_at' => now(),
            ]);
            if ($result['email']) $found++;
        }
        return compact('checked', 'found');
    }

    private function find(string $website): array
    {
        $parts = parse_url($website);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || ! $host || ! $this->publicHost($host)) return ['email' => null, 'url' => null];
        $base = $scheme.'://'.$host;
        foreach ([$base, $base.'/careers', $base.'/jobs', $base.'/contact'] as $url) {
            try {
                $response = Http::withUserAgent('Leadspace public contact discovery/1.0')->timeout(8)->connectTimeout(4)
                    ->withOptions(['allow_redirects' => false])->get($url);
                if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'text/html')) continue;
                if ($email = $this->email($response->body(), $host)) return ['email' => $email, 'url' => $url];
            } catch (\Throwable) {
                continue;
            }
        }
        return ['email' => null, 'url' => null];
    }

    private function email(string $html, string $companyHost): ?string
    {
        $text = html_entity_decode(strip_tags($html));
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);
        $emails = array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
        $emails = array_filter($emails, fn ($email) => RecruitmentEmail::acceptWebsite($email));
        usort($emails, function ($a, $b) use ($companyHost) {
            $score = fn ($email) => (str_ends_with($email, '@'.$companyHost) ? 10 : 0)
                + (preg_match('/^(careers|jobs|recruit|recruiting|talent|hr|people)@/', $email) ? 20 : 0);
            return $score($b) <=> $score($a);
        });
        return $emails[0] ?? null;
    }

    private function publicHost(string $host): bool
    {
        $ips = gethostbynamel($host) ?: [];
        if (! $ips) return false;
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }
}
