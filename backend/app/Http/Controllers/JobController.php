<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\Workspace;
use App\Support\Audit;
use App\Services\JobFeedSyncService;
use App\Services\CompanyEmailEnricher;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobController extends Controller
{
    private const STATUSES = ['new', 'saved', 'applied', 'interview', 'rejected', 'closed'];

    private function authorizeWorkspace(Request $request, Workspace $workspace, bool $write = false): void
    {
        if ($write && $request->bearerToken()) {
            abort_unless($request->user()->tokenCan('jobs:write'), 403, 'This API token cannot import jobs.');
        }
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($workspace->suspended_at, 403, 'This workspace is suspended.');
        abort_if($workspace->plan->slug === 'free', 402, 'A paid subscription is required to access jobs.');
        abort_if($write && $member->pivot->role === 'viewer', 403, 'Viewers cannot modify jobs.');
    }

    public function index(Request $request, Workspace $workspace)
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'q' => 'nullable|string|max:150', 'source' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100', 'workplace_type' => 'nullable|string|max:30',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'sort' => 'nullable|in:posted_at,created_at,title,company_name',
            'direction' => 'nullable|in:asc,desc', 'per_page' => 'nullable|integer|in:15,30,50',
        ]);

        return $this->filtered($workspace, $data)
            ->orderBy($data['sort'] ?? 'created_at', $data['direction'] ?? 'desc')
            ->orderByDesc('id')->paginate($data['per_page'] ?? 15);
    }

    public function filters(Request $request, Workspace $workspace): array
    {
        $this->authorizeWorkspace($request, $workspace);
        $values = fn (string $column) => $workspace->jobs()->whereNotNull($column)->where($column, '!=', '')
            ->select($column)->selectRaw('COUNT(*) as total')->groupBy($column)->orderBy($column)->get();

        return ['sources' => $values('source_platform'), 'countries' => $values('country'),
            'workplace_types' => $values('workplace_type'), 'statuses' => self::STATUSES,
            'with_email' => $workspace->jobs()->whereNotNull('contact_email')->count(),
            'with_domain' => $workspace->jobs()->whereNotNull('company_domain')->count(),
            'latest_sync' => $workspace->jobSyncRuns()->latest('started_at')->first(),
        ];
    }

    public function sync(Request $request, Workspace $workspace, JobFeedSyncService $service)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $last = $workspace->jobSyncRuns()->where('status', 'completed')->latest('started_at')->first();
        abort_if($last && $last->started_at->gt(now()->subMinutes(10)), 429, 'Jobs were synced recently. Please wait before running another sync.');
        $run = $service->sync($workspace);
        Audit::record('jobs.synced', $run->id, ['created' => $run->created_count, 'updated' => $run->updated_count, 'failed' => $run->failed_count], $workspace->id);
        return $run;
    }

    public function enrich(Request $request, Workspace $workspace, CompanyEmailEnricher $service): array
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $result = $service->enrich($workspace, 25);
        Audit::record('jobs.emails_enriched', null, $result, $workspace->id);
        return $result;
    }

    public function store(Request $request, Workspace $workspace)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $data = $this->validatedJob($request);
        $job = $this->upsert($workspace, $data);
        Audit::record('job.saved', $job->id, ['source' => $job->source_platform], $workspace->id);

        return response()->json($job, 201);
    }

    public function import(Request $request, Workspace $workspace): array
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $data = $request->validate([
            'source_platform' => 'required|string|max:100', 'jobs' => 'required|array|min:1|max:1000',
            'jobs.*' => 'required|array',
        ]);
        $created = $updated = $failed = 0;
        $errors = [];
        DB::transaction(function () use ($workspace, $data, &$created, &$updated, &$failed, &$errors) {
            foreach ($data['jobs'] as $index => $raw) {
                try {
                    $normalized = $this->normalize($raw + ['source_platform' => $data['source_platform']]);
                    if (! $normalized['title']) {
                        throw new \InvalidArgumentException('Job title is required.');
                    }
                    $hash = $this->hash($normalized);
                    $exists = $workspace->jobs()->where('dedupe_hash', $hash)->exists();
                    $this->upsert($workspace, $normalized);
                    $exists ? $updated++ : $created++;
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($errors) < 20) $errors[] = ['row' => $index + 2, 'message' => $e->getMessage()];
                }
            }
        });
        Audit::record('jobs.imported', null, compact('created', 'updated', 'failed') + ['source' => $data['source_platform']], $workspace->id);

        return compact('created', 'updated', 'failed', 'errors');
    }

    public function show(Request $request, Workspace $workspace, int $job): Job
    {
        $this->authorizeWorkspace($request, $workspace);
        return $workspace->jobs()->findOrFail($job);
    }

    public function update(Request $request, Workspace $workspace, int $job): Job
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $record = $workspace->jobs()->findOrFail($job);
        $data = $request->validate(['status' => 'required|in:'.implode(',', self::STATUSES)]);
        $record->update($data);
        Audit::record('job.status_updated', $record->id, $data, $workspace->id);
        return $record->fresh();
    }

    public function destroy(Request $request, Workspace $workspace, int $job)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $record = $workspace->jobs()->findOrFail($job);
        Audit::record('job.deleted', $record->id, [], $workspace->id);
        $record->delete();
        return response()->noContent();
    }

    public function destroyAll(Request $request, Workspace $workspace): array
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $deleted = $workspace->jobs()->delete();
        Audit::record('jobs.deleted_all', null, ['deleted' => $deleted], $workspace->id);
        return ['deleted' => $deleted];
    }

    private function filtered(Workspace $workspace, array $data)
    {
        return $workspace->jobs()
            ->when($data['q'] ?? null, fn ($query, $term) => $query->where(fn ($q) => $q
                ->where('title', 'like', "%$term%")->orWhere('company_name', 'like', "%$term%")
                ->orWhere('location', 'like', "%$term%")->orWhere('description', 'like', "%$term%")))
            ->when($data['source'] ?? null, fn ($q, $v) => $q->where('source_platform', $v))
            ->when($data['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->when($data['workplace_type'] ?? null, fn ($q, $v) => $q->where('workplace_type', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
    }

    private function validatedJob(Request $request): array
    {
        return $request->validate([
            'source_platform' => 'required|string|max:100', 'source_job_id' => 'nullable|string|max:255',
            'source_url' => 'nullable|url:http,https|max:2000', 'title' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255', 'company_website' => 'nullable|url:http,https|max:255',
            'location' => 'nullable|string|max:255', 'country' => 'nullable|string|max:100',
            'workplace_type' => 'nullable|in:remote,hybrid,onsite,unknown', 'employment_type' => 'nullable|string|max:50',
            'seniority_level' => 'nullable|string|max:50', 'salary_min' => 'nullable|integer|min:0',
            'salary_max' => 'nullable|integer|min:0', 'salary_currency' => 'nullable|string|size:3',
            'salary_period' => 'nullable|string|max:30', 'description' => 'nullable|string|max:100000',
            'requirements' => 'nullable|string|max:100000', 'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255', 'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'posted_at' => 'nullable|date', 'expires_at' => 'nullable|date', 'metadata' => 'nullable|array',
        ]);
    }

    private function normalize(array $raw): array
    {
        $get = fn (array $keys) => Arr::first($keys, fn ($key) => filled(Arr::get($raw, $key)), null) !== null
            ? Arr::get($raw, Arr::first($keys, fn ($key) => filled(Arr::get($raw, $key)))) : null;
        $url = $get(['source_url', 'job_url', 'url', 'link', 'apply_url']);
        $workplace = Str::lower((string) $get(['workplace_type', 'remote', 'work_model']));
        if (str_contains($workplace, 'remote') || in_array($workplace, ['yes', 'true', '1'])) $workplace = 'remote';
        elseif (str_contains($workplace, 'hybrid')) $workplace = 'hybrid';
        elseif (str_contains($workplace, 'site') || str_contains($workplace, 'office')) $workplace = 'onsite';
        else $workplace = 'unknown';

        return [
            'source_platform' => trim((string) $get(['source_platform', 'source', 'platform'])),
            'source_job_id' => $get(['source_job_id', 'job_id', 'external_id', 'id']), 'source_url' => $url,
            'title' => trim((string) $get(['title', 'job_title', 'position', 'role'])),
            'company_name' => $get(['company_name', 'company', 'employer']),
            'company_website' => $get(['company_website', 'company_url', 'employer_website']),
            'location' => $get(['location', 'job_location', 'city']), 'country' => $get(['country', 'job_country']),
            'workplace_type' => $workplace, 'employment_type' => $get(['employment_type', 'job_type', 'type']),
            'seniority_level' => $get(['seniority_level', 'seniority', 'experience_level']),
            'salary_min' => $get(['salary_min', 'min_salary']), 'salary_max' => $get(['salary_max', 'max_salary']),
            'salary_currency' => $get(['salary_currency', 'currency']), 'salary_period' => $get(['salary_period', 'pay_period']),
            'description' => $get(['description', 'job_description', 'summary']),
            'requirements' => $get(['requirements', 'qualifications']), 'contact_name' => $get(['contact_name', 'recruiter_name']),
            'contact_email' => $get(['contact_email', 'recruiter_email', 'email']), 'status' => $get(['status']) ?: 'new',
            'posted_at' => $get(['posted_at', 'date_posted', 'posted_date']), 'expires_at' => $get(['expires_at', 'valid_through']),
            'scraped_at' => now(), 'metadata' => $raw,
        ];
    }

    private function hash(array $data): string
    {
        if (filled($data['source_job_id'] ?? null)) return hash('sha256', Str::lower($data['source_platform'].'|'.$data['source_job_id']));
        if (filled($data['source_url'] ?? null)) return hash('sha256', Str::lower(rtrim($data['source_url'], '/')));
        return hash('sha256', Str::lower(trim(($data['title'] ?? '').'|'.($data['company_name'] ?? '').'|'.($data['location'] ?? ''))));
    }

    private function upsert(Workspace $workspace, array $data): Job
    {
        $data['source_platform'] = trim($data['source_platform']);
        $data['dedupe_hash'] = $this->hash($data);
        return $workspace->jobs()->updateOrCreate(['dedupe_hash' => $data['dedupe_hash']], $data);
    }
}
