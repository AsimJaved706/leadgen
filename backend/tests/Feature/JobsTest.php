<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
