<?php

namespace App\Http\Controllers;

use App\Models\JobWorkerRequest;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobWorkerController extends Controller
{
    private function member(Request $request, Workspace $workspace, bool $write = false): void
    {
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($workspace->suspended_at, 403, 'This workspace is suspended.');
        abort_if($workspace->plan->slug === 'free', 402, 'A paid subscription is required to access jobs.');
        abort_if($write && $member->pivot->role === 'viewer', 403, 'Viewers cannot run the job worker.');
    }

    public function status(Request $request, Workspace $workspace): array
    {
        $this->member($request, $workspace);
        return ['requests' => $workspace->jobWorkerRequests()->latest()->limit(5)->get()];
    }

    public function store(Request $request, Workspace $workspace)
    {
        $this->member($request, $workspace, true);
        $data = $request->validate(['type' => 'required|in:scrape,enrich']);
        $active = $workspace->jobWorkerRequests()->where('type', $data['type'])->whereIn('status', ['pending', 'running'])->first();
        if ($active) return response()->json(['message' => 'This worker task is already queued or running.', 'request' => $active], 409);
        $parameters = $data['type'] === 'scrape'
            ? ['search_term' => 'Full Stack Developer', 'location' => 'Remote', 'results_per_source' => 50, 'hours_old' => 24,
                'sources' => ['linkedin', 'indeed', 'glassdoor', 'google', 'zip_recruiter', 'bayt', 'naukri']]
            : ['limit' => 1000];
        $job = $workspace->jobWorkerRequests()->create(['requested_by' => $request->user()->id, 'type' => $data['type'], 'status' => 'pending', 'parameters' => $parameters]);
        Audit::record('jobs.worker_queued', $job->id, ['type' => $job->type], $workspace->id);
        return response()->json($job, 202);
    }

    public function claim(Request $request)
    {
        abort_unless($request->bearerToken() && $request->user()->tokenCan('jobs:write'), 403, 'A jobs:write worker token is required.');
        $job = DB::transaction(function () use ($request) {
            $workspaceIds = $request->user()->workspaces()->pluck('workspaces.id');
            $job = JobWorkerRequest::whereIn('workspace_id', $workspaceIds)->where('status', 'pending')->oldest()->lockForUpdate()->first();
            if ($job) $job->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
            return $job?->fresh();
        });
        return $job ? response()->json($job) : response()->noContent();
    }

    public function complete(Request $request, JobWorkerRequest $workerRequest)
    {
        abort_unless($request->bearerToken() && $request->user()->tokenCan('jobs:write'), 403, 'A jobs:write worker token is required.');
        abort_unless($request->user()->workspaces()->where('workspaces.id', $workerRequest->workspace_id)->exists(), 404);
        abort_unless($workerRequest->status === 'running', 409, 'This request is not running.');
        $data = $request->validate(['status' => 'required|in:completed,failed', 'result' => 'nullable|array', 'error' => 'nullable|string|max:10000']);
        $workerRequest->update($data + ['finished_at' => now()]);
        Audit::record('jobs.worker_finished', $workerRequest->id, ['type' => $workerRequest->type, 'status' => $data['status']], $workerRequest->workspace_id);
        return $workerRequest->fresh();
    }
}
