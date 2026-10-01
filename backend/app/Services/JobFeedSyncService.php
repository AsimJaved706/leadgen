<?php

namespace App\Services;

use App\Models\JobSyncRun;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class JobFeedSyncService
{
    private const BLOCKED_DOMAINS = ['himalayas.app', 'remotelanders.com', 'linkedin.com', 'indeed.com', 'glassdoor.com'];

    public function sync(Workspace $workspace, int $perSource = 100): JobSyncRun
    {
        $run = JobSyncRun::create(['workspace_id' => $workspace->id, 'status' => 'running', 'started_at' => now(), 'sources' => []]);
        $created = $updated = $failed = 0;
        $sources = [];
        try {
            foreach (['Himalayas' => $this->himalayas($perSource), 'Remote Landers' => $this->remoteLanders($perSource)] as $source => $rows) {
                $sourceCreated = $sourceUpdated = $sourceFailed = 0;
                foreach ($rows as $raw) {
                    try {
                        $data = $source === 'Himalayas' ? $this->mapHimalayas($raw) : $this->mapRemoteLanders($raw);
                        $hash = hash('sha256', Str::lower($source.'|'.$data['source_job_id']));
                        $existing = $workspace->jobs()->where('dedupe_hash', $hash)->first();
                        if ($existing) {
                            unset($data['status']);
                            $existing->update($data + ['dedupe_hash' => $hash]);
                            $updated++; $sourceUpdated++;
                        } else {
                            $workspace->jobs()->create($data + ['dedupe_hash' => $hash]);
                            $created++; $sourceCreated++;
                        }
                    } catch (\Throwable $e) {
                        report($e); $failed++; $sourceFailed++;
                    }
                }
                $sources[$source] = ['received' => count($rows), 'created' => $sourceCreated, 'updated' => $sourceUpdated, 'failed' => $sourceFailed];
            }
            $run->update(['status' => 'completed', 'created_count' => $created, 'updated_count' => $updated, 'failed_count' => $failed, 'sources' => $sources, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'created_count' => $created, 'updated_count' => $updated, 'failed_count' => $failed + 1, 'sources' => $sources, 'error' => Str::limit($e->getMessage(), 2000), 'finished_at' => now()]);
            throw $e;
        }
        return $run->fresh();
    }

    private function himalayas(int $limit): array
    {
        $jobs = []; $cursor = null;
        while (count($jobs) < $limit) {
            $take = min(20, $limit - count($jobs));
            $query = ['limit' => $take];
            if ($cursor) $query['cursor'] = $cursor;
            $response = Http::acceptJson()->withUserAgent('Leadspace Job Sync/1.0')->timeout(25)->retry(2, 500)
                ->get('https://himalayas.app/jobs/api', $query)->throw()->json();
            $batch = $response['jobs'] ?? [];
            $jobs = array_merge($jobs, $batch);
            $cursor = $response['nextCursor'] ?? null;
            if (! $cursor || ! $batch) break;
        }
        return array_slice($jobs, 0, $limit);
    }

    private function remoteLanders(int $limit): array
    {
        $response = Http::acceptJson()->withUserAgent('Leadspace Job Sync/1.0')->timeout(25)->retry(2, 500)
            ->get('https://remotelanders.com/api/jobs', ['limit' => min($limit, 100), 'page' => 1])->throw()->json();
        return array_slice($response['jobs'] ?? [], 0, $limit);
    }

    private function mapHimalayas(array $job): array
    {
        $description = $job['description'] ?? $job['excerpt'] ?? null;
        $email = $this->publishedEmail($description);
        return [
            'source_platform' => 'Himalayas', 'source_job_id' => $job['guid'] ?? md5(json_encode($job)),
            'source_url' => $job['applicationLink'] ?? $job['guid'] ?? null, 'title' => $job['title'] ?? 'Untitled job',
            'company_name' => $job['companyName'] ?? null, 'location' => implode(', ', $job['locationRestrictions'] ?? []),
            'country' => ($job['locationRestrictions'][0] ?? null), 'workplace_type' => 'remote',
            'employment_type' => $job['employmentType'] ?? null, 'seniority_level' => implode(', ', $job['seniority'] ?? []),
            'salary_min' => $job['minSalary'] ?? null, 'salary_max' => $job['maxSalary'] ?? null,
            'salary_currency' => $job['currency'] ?? null, 'salary_period' => $job['salaryPeriod'] ?? null,
            'description' => $description, 'contact_email' => $email,
            'email_discovery_status' => $email ? 'published' : 'not_found', 'email_source' => $email ? 'job_description' : null,
            'posted_at' => isset($job['pubDate']) ? date('Y-m-d H:i:s', (int) $job['pubDate']) : null,
            'expires_at' => isset($job['expiryDate']) ? date('Y-m-d H:i:s', (int) $job['expiryDate']) : null,
            'scraped_at' => now(), 'enriched_at' => now(), 'status' => 'new', 'metadata' => $job,
        ];
    }

    private function mapRemoteLanders(array $job): array
    {
        $website = $job['companyWebsite'] ?? null;
        $domain = $this->domain($website);
        $description = $job['description'] ?? null;
        $email = $this->publishedEmail($description);
        return [
            'source_platform' => 'Remote Landers', 'source_job_id' => $job['slug'] ?? md5(json_encode($job)),
            'source_url' => $job['applyUrl'] ?? $job['url'] ?? null, 'title' => $job['title'] ?? 'Untitled job',
            'company_name' => $job['company'] ?? null, 'company_website' => $website, 'company_domain' => $domain,
            'location' => $job['location'] ?? null, 'country' => $job['location'] ?? null, 'workplace_type' => 'remote',
            'employment_type' => $job['type'] ?? null, 'seniority_level' => $job['level'] ?? null,
            'description' => $description, 'contact_email' => $email,
            'email_discovery_status' => $email ? 'published' : 'not_found', 'email_source' => $email ? 'job_description' : null,
            'posted_at' => $job['postedDate'] ?? null, 'scraped_at' => now(), 'enriched_at' => now(),
            'status' => 'new', 'metadata' => $job,
        ];
    }

    private function publishedEmail(?string $content): ?string
    {
        if (! $content) return null;
        $text = html_entity_decode(strip_tags($content));
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);
        foreach (array_unique(array_map('strtolower', $matches[0] ?? [])) as $email) {
            if (! preg_match('/^(no-?reply|noreply|example)@/', $email) && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        }
        return null;
    }

    private function domain(?string $url): ?string
    {
        if (! $url) return null;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if (! $host || in_array($host, self::BLOCKED_DOMAINS, true)) return null;
        return $host;
    }
}
