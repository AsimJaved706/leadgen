<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function workspace(User $user, string $role = 'owner'): Workspace
    {
        $workspace = Workspace::create(['name' => 'Jobs workspace', 'owner_id' => $user->id, 'plan_id' => Plan::where('slug', 'professional')->value('id')]);
        $workspace->members()->attach($user->id, ['role' => $role]);
        return $workspace;
    }

    public function test_multi_source_import_normalizes_deduplicates_filters_and_scopes_jobs(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $other = $this->workspace(User::factory()->create());
        $this->actingAs($user);

        $payload = ['source_platform' => 'Indeed', 'jobs' => [[
            'job_id' => 'abc-123', 'job_title' => 'Senior PHP Developer', 'company' => 'Acme',
            'job_url' => 'https://example.com/jobs/abc-123', 'job_location' => 'Toronto, ON',
            'country' => 'Canada', 'remote' => 'Remote', 'date_posted' => '2026-10-01',
        ]]];
        $this->postJson("/api/workspaces/{$workspace->id}/jobs/import", $payload)
            ->assertOk()->assertJson(['created' => 1, 'updated' => 0, 'failed' => 0]);
        $this->postJson("/api/workspaces/{$workspace->id}/jobs/import", $payload)
            ->assertOk()->assertJson(['created' => 0, 'updated' => 1, 'failed' => 0]);
        $this->assertDatabaseCount('workspace_jobs', 1);
        $this->getJson("/api/workspaces/{$workspace->id}/jobs?q=PHP&source=Indeed&country=Canada&workplace_type=remote")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Senior PHP Developer');
        $this->getJson("/api/workspaces/{$other->id}/jobs")->assertNotFound();
    }

    public function test_import_rejects_jobs_from_asian_countries(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $payload = ['source_platform' => 'LinkedIn', 'jobs' => [
            ['job_id' => 'pk-1', 'job_title' => 'Developer', 'country' => 'Pakistan', 'location' => 'Lahore, Pakistan'],
            ['job_id' => 'ca-1', 'job_title' => 'Developer', 'country' => 'Canada', 'location' => 'Toronto, Canada'],
        ]];

        $this->actingAs($user)->postJson("/api/workspaces/{$workspace->id}/jobs/import", $payload)
            ->assertOk()->assertJson(['created' => 1, 'failed' => 1]);
        $this->assertDatabaseMissing('workspace_jobs', ['source_job_id' => 'pk-1']);
        $this->assertDatabaseHas('workspace_jobs', ['source_job_id' => 'ca-1', 'country' => 'Canada']);
    }

    public function test_workspace_can_enable_asian_country_jobs_for_imports_and_worker_syncs(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $this->actingAs($user);

        $this->getJson("/api/workspaces/{$workspace->id}/jobs/settings")
            ->assertOk()->assertJsonPath('include_asian_job_countries', false);
        $this->patchJson("/api/workspaces/{$workspace->id}/jobs/settings", ['include_asian_job_countries' => true])
            ->assertOk()->assertJsonPath('include_asian_job_countries', true);
        $this->postJson("/api/workspaces/{$workspace->id}/jobs/import", ['source_platform' => 'LinkedIn', 'jobs' => [[
            'job_id' => 'in-1', 'job_title' => 'Remote Developer', 'country' => 'India', 'location' => 'Remote, India',
        ]]])->assertOk()->assertJson(['created' => 1, 'failed' => 0]);
        $this->postJson("/api/workspaces/{$workspace->id}/jobs/worker-requests", [
            'type' => 'scrape', 'sources' => ['linkedin'], 'search_term' => 'Developer', 'location' => 'Remote',
        ])->assertStatus(202)->assertJsonPath('parameters.exclude_asia', false);
        $this->assertDatabaseHas('workspace_jobs', ['source_job_id' => 'in-1', 'country' => 'India']);
    }

    public function test_job_status_permissions_and_paid_access_are_enforced(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $job = $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'Engineer', 'dedupe_hash' => hash('sha256', 'job')]);
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)->patchJson("/api/workspaces/{$workspace->id}/jobs/{$job->id}", ['status' => 'applied'])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/workspaces/{$workspace->id}/jobs/{$job->id}", ['status' => 'applied'])
            ->assertOk()->assertJsonPath('status', 'applied');
        $workspace->update(['plan_id' => Plan::where('slug', 'free')->value('id')]);
        $this->getJson("/api/workspaces/{$workspace->id}/jobs")->assertPaymentRequired();
    }

    public function test_manual_job_can_store_a_contact_email(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);

        $this->actingAs($user)->postJson("/api/workspaces/{$workspace->id}/jobs", [
            'source_platform' => 'Manual',
            'title' => 'Senior Full Stack Developer',
            'company_name' => 'Example Company',
            'contact_name' => 'Hiring Manager',
            'contact_email' => 'Recruiter@Example.com',
        ])->assertCreated()
            ->assertJsonPath('contact_email', 'recruiter@example.com')
            ->assertJsonPath('email_discovery_status', 'manual_verified');

        $this->assertDatabaseHas('workspace_jobs', [
            'workspace_id' => $workspace->id,
            'contact_name' => 'Hiring Manager',
            'contact_email' => 'recruiter@example.com',
            'email_discovery_status' => 'manual_verified',
        ]);
    }

    public function test_free_feeds_sync_daily_fields_domains_and_published_emails(): void
    {
        Http::fake([
            'himalayas.app/*' => Http::response(['jobs' => [[
                'guid' => 'h-1', 'title' => 'Laravel Developer', 'companyName' => 'Example Labs',
                'locationRestrictions' => ['Canada'], 'employmentType' => 'Full Time',
                'description' => '<p>Email careers@examplelabs.com to apply.</p>',
                'applicationLink' => 'https://himalayas.app/jobs/h-1', 'pubDate' => 1790812800,
            ]]]),
            'remotelanders.com/*' => Http::response(['jobs' => [[
                'slug' => 'r-1', 'title' => 'React Engineer', 'company' => 'Acme',
                'companyWebsite' => 'https://www.acme.test/about', 'location' => 'United States',
                'type' => 'Full-time', 'applyUrl' => 'https://jobs.example/r-1', 'postedDate' => '2026-10-01',
            ]]]),
            '*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $this->actingAs($user)->postJson("/api/workspaces/{$workspace->id}/jobs/sync", ['tags' => ['Laravel']])
            ->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('status', 'completed')
            ->assertJsonPath('sources.filters.tags.0', 'laravel');
        $this->assertDatabaseHas('workspace_jobs', ['source_job_id' => 'h-1', 'contact_email' => 'careers@examplelabs.com', 'email_discovery_status' => 'published_job']);
        $this->assertDatabaseMissing('workspace_jobs', ['source_job_id' => 'r-1']);
        $this->getJson("/api/workspaces/{$workspace->id}/jobs/filters")
            ->assertOk()->assertJsonPath('with_email', 1)->assertJsonPath('with_domain', 0)->assertJsonPath('latest_sync.status', 'completed');
    }

    public function test_scoped_token_can_import_jobs_but_read_only_token_cannot(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $payload = ['source_platform' => 'LinkedIn', 'jobs' => [[
            'source_job_id' => 'li-123', 'title' => 'Full Stack Developer',
            'source_url' => 'https://www.linkedin.com/jobs/view/123',
        ]]];
        $writeToken = $user->createToken('worker', ['jobs:write'], now()->addDay())->plainTextToken;
        $this->withToken($writeToken)->postJson("/api/workspaces/{$workspace->id}/jobs/import", $payload)
            ->assertOk()->assertJsonPath('created', 1);
        $this->app['auth']->forgetGuards();
        $readToken = $user->createToken('reader', ['jobs:read'], now()->addDay())->plainTextToken;
        $this->withToken($readToken)->postJson("/api/workspaces/{$workspace->id}/jobs/import", $payload)->assertForbidden();
    }

    public function test_dashboard_can_queue_and_worker_can_complete_scrape(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $payload = ['type' => 'scrape', 'sources' => ['linkedin', 'indeed'], 'search_term' => 'Laravel Developer',
            'location' => 'United States', 'results_per_source' => 25];
        $this->actingAs($user)->postJson("/api/workspaces/{$workspace->id}/jobs/worker-requests", $payload)
            ->assertStatus(202)->assertJsonPath('status', 'pending')
            ->assertJsonPath('parameters.sources.0', 'linkedin')->assertJsonPath('parameters.sources.1', 'indeed')
            ->assertJsonPath('parameters.search_term', 'Laravel Developer')->assertJsonPath('parameters.results_per_source', 25);
        $this->postJson("/api/workspaces/{$workspace->id}/jobs/worker-requests", $payload)->assertConflict();

        $token = $user->createToken('ec2', ['jobs:worker'], now()->addDay())->plainTextToken;
        $claim = $this->withToken($token)->postJson('/api/worker/job-requests/claim')->assertOk()
            ->assertJsonPath('status', 'running')->json();
        $this->withToken($token)->postJson("/api/worker/job-requests/{$claim['id']}/complete", [
            'status' => 'completed', 'result' => ['fetched' => 50],
        ])->assertOk()->assertJsonPath('result.fetched', 50);
        $this->actingAs($user)->getJson("/api/workspaces/{$workspace->id}/jobs/worker-status")
            ->assertOk()->assertJsonPath('requests.0.status', 'completed');
    }

    public function test_worker_service_token_can_claim_and_import_for_another_paid_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $serviceAccount = User::factory()->create();
        $workspace->jobWorkerRequests()->create([
            'requested_by' => $owner->id, 'type' => 'scrape', 'status' => 'pending', 'parameters' => ['sources' => ['linkedin']],
        ]);
        $token = $serviceAccount->createToken('ec2', ['jobs:worker', 'jobs:write'], now()->addDay())->plainTextToken;

        $this->withToken($token)->postJson('/api/worker/job-requests/claim')->assertOk()
            ->assertJsonPath('workspace_id', $workspace->id)->assertJsonPath('status', 'running');
        $this->withToken($token)->postJson("/api/workspaces/{$workspace->id}/jobs/import", ['source_platform' => 'LinkedIn', 'jobs' => [[
            'source_job_id' => 'worker-1', 'title' => 'Remote Developer', 'country' => 'Canada',
        ]]])->assertOk()->assertJsonPath('created', 1);
        $this->withToken($token)->getJson("/api/workspaces/{$workspace->id}/jobs?per_page=15")
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_stale_worker_request_is_released_when_dashboard_checks_status(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $stale = $workspace->jobWorkerRequests()->create([
            'requested_by' => $user->id,
            'type' => 'scrape',
            'status' => 'running',
            'parameters' => ['sources' => ['linkedin']],
            'created_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
        ]);

        $this->actingAs($user)->getJson("/api/workspaces/{$workspace->id}/jobs/worker-status")
            ->assertOk()->assertJsonPath('requests.0.status', 'failed');
        $this->assertNotNull($stale->fresh()->finished_at);
    }

    public function test_worker_queues_email_discovery_only_once_each_day(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $token = $user->createToken('ec2-daily', ['jobs:worker'], now()->addDay())->plainTextToken;

        $claim = $this->withToken($token)->postJson('/api/worker/job-requests/claim')->assertOk()
            ->assertJsonPath('type', 'enrich')->assertJsonPath('status', 'running')
            ->assertJsonPath('parameters.trigger', 'daily')->assertJsonPath('parameters.limit', 1000)->json();
        $this->withToken($token)->postJson("/api/worker/job-requests/{$claim['id']}/complete", [
            'status' => 'completed', 'result' => ['checked' => 1000, 'found' => 12],
        ])->assertOk();

        $this->withToken($token)->postJson('/api/worker/job-requests/claim')->assertNoContent();
        $this->assertDatabaseCount('job_worker_requests', 1);
        $this->assertDatabaseHas('job_worker_requests', ['workspace_id' => $workspace->id, 'type' => 'enrich', 'status' => 'completed']);
    }

    public function test_jobs_are_sorted_by_arrival_date_and_owner_can_delete_all(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->jobs()->create(['source_platform' => 'Indeed', 'title' => 'Older', 'dedupe_hash' => hash('sha256', 'older'), 'created_at' => now()->subDay()]);
        $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'Newest', 'dedupe_hash' => hash('sha256', 'newest'), 'created_at' => now()]);

        $this->actingAs($owner)->getJson("/api/workspaces/{$workspace->id}/jobs")
            ->assertOk()->assertJsonPath('data.0.title', 'Newest');
        $this->deleteJson("/api/workspaces/{$workspace->id}/jobs")
            ->assertOk()->assertJsonPath('deleted', 2);
        $this->assertDatabaseMissing('workspace_jobs', ['workspace_id' => $workspace->id]);
    }

    public function test_email_filter_and_job_quality_scores_are_returned(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'source_url' => 'https://linkedin.com/jobs/1',
            'title' => 'Remote Developer', 'company_name' => 'Acme', 'company_domain' => 'acme.test',
            'workplace_type' => 'remote', 'contact_email' => 'jobs@acme.test', 'description' => str_repeat('Detailed role information. ', 30),
            'posted_at' => now(), 'email_discovery_status' => 'published_job', 'dedupe_hash' => hash('sha256', 'scored')]);
        $workspace->jobs()->create(['source_platform' => 'Unknown', 'title' => 'Other', 'dedupe_hash' => hash('sha256', 'no-email')]);

        $this->actingAs($user)->getJson("/api/workspaces/{$workspace->id}/jobs?email_status=with_email")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.risk_level', 'low')
            ->assertJsonPath('data.0.trust_score', 100)->assertJsonPath('data.0.opportunity_score', 100);
        $this->getJson("/api/workspaces/{$workspace->id}/jobs?email_status=without_email")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Other');
    }
}
