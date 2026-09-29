<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function workspace(User $user, string $role = 'owner'): Workspace
    {
        $w = Workspace::create(['name' => 'Test workspace', 'owner_id' => $user->id, 'plan_id' => Plan::where('slug', 'professional')->first()->id]);
        $w->members()->attach($user->id, ['role' => $role]);

        return $w;
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_registration_creates_workspace_hashes_password_and_ignores_privilege_injection(): void
    {
        $this->postJson('/api/register', ['name' => 'Jamie', 'email' => 'jamie@example.com', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!', 'workspace' => 'Acme', 'is_super_admin' => true])->assertCreated()->assertJsonPath('is_super_admin', false)->assertJsonMissingPath('password');
        $u = User::first();
        $this->assertTrue(Hash::check('StrongPassword123!', $u->password));
        $this->assertSame('owner', $u->workspaces()->first()->pivot->role);
        $this->assertSame(6, $u->workspaces()->first()->emailTemplates()->count());
        $this->assertDatabaseHas('email_templates', ['workspace_id' => $u->workspaces()->first()->id, 'name' => 'Professional Introduction']);
        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_registration_requires_a_symbol_and_matching_confirmation(): void
    {
        $base = ['name' => 'Jamie', 'email' => 'jamie@example.com', 'workspace' => 'Acme'];
        $this->postJson('/api/register', $base + ['password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', $base + ['password' => 'StrongPassword123!', 'password_confirmation' => 'DifferentPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_can_update_profile_and_password_with_current_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'password' => 'ExistingPassword123!']);
        $this->actingAs($user)->putJson('/api/profile', ['name' => 'Jamie Parker', 'current_password' => 'wrong', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/profile', ['name' => 'Jamie Parker', 'current_password' => 'ExistingPassword123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertOk()->assertJsonPath('name', 'Jamie Parker');
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'user.profile_updated']);
    }

    public function test_guests_and_members_cannot_access_any_admin_endpoint(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $u = User::factory()->create();
        $this->actingAs($u);
        foreach (['overview', 'users', 'workspaces', 'plans', 'audit-logs'] as $path) {
            $this->getJson('/api/admin/'.$path)->assertForbidden();
        }
        $this->patchJson('/api/admin/users/'.$u->id, ['is_super_admin' => true])->assertForbidden();
        $this->patchJson('/api/admin/plans/1', ['monthly_price_cents' => 1])->assertForbidden();
    }

    public function test_cross_workspace_reads_writes_and_deletes_are_blocked(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $wa = $this->workspace($a);
        $wb = $this->workspace($b);
        $lead = $wb->leads()->create(['name' => 'Private lead']);
        $wb->lists()->create(['name' => 'Private list']);
        $this->actingAs($a);
        foreach (['leads', 'lists', 'summary'] as $resource) {
            $this->getJson('/api/workspaces/'.$wb->id.'/'.$resource)->assertNotFound();
        }
        $this->postJson('/api/workspaces/'.$wb->id.'/leads', ['name' => 'Intruder'])->assertNotFound();
        $this->getJson('/api/workspaces/'.$wb->id.'/leads/'.$lead->id)->assertNotFound();
        $this->getJson('/api/workspaces/'.$wa->id.'/leads/'.$lead->id)->assertNotFound();
        $this->postJson('/api/workspaces/'.$wb->id.'/lists', ['name' => 'Intruder'])->assertNotFound();
        $this->deleteJson('/api/workspaces/'.$wa->id.'/leads/'.$lead->id)->assertNotFound();
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_viewers_can_read_but_cannot_write(): void
    {
        $u = User::factory()->create();
        $w = $this->workspace($u, 'viewer');
        $lead = $w->leads()->create(['name' => 'Existing']);
        $this->actingAs($u);
        $this->getJson('/api/workspaces/'.$w->id.'/leads')->assertOk();
        $this->postJson('/api/workspaces/'.$w->id.'/leads', ['name' => 'New'])->assertForbidden();
        $this->postJson('/api/workspaces/'.$w->id.'/lists', ['name' => 'New'])->assertForbidden();
        $this->deleteJson('/api/workspaces/'.$w->id.'/leads/'.$lead->id)->assertForbidden();
    }

    public function test_free_workspace_is_locked_but_billing_remains_available(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Free workspace', 'owner_id' => $user->id, 'plan_id' => Plan::where('slug', 'free')->firstOrFail()->id]);
        $workspace->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        $this->getJson('/api/workspaces/'.$workspace->id.'/summary')->assertStatus(402);
        $this->getJson('/api/workspaces/'.$workspace->id.'/leads')->assertStatus(402);
        $this->getJson('/api/workspaces/'.$workspace->id.'/email-templates')->assertStatus(402);
        $this->getJson('/api/workspaces/'.$workspace->id.'/billing')->assertOk()->assertJsonPath('workspace.plan.slug', 'free');
    }

    public function test_extension_token_is_short_lived_and_context_is_server_authoritative(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $response = $this->actingAs($user)->postJson('/api/extension/token', ['extension_id' => str_repeat('a', 32)])
            ->assertOk()->assertJsonStructure(['token', 'expires_at']);
        $this->assertTrue(now()->diffInMinutes($response->json('expires_at')) <= 15.1);
        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/extension/context')
            ->assertOk()->assertJsonPath('workspaces.0.id', $workspace->id)
            ->assertJsonPath('workspaces.0.access.allowed', true)
            ->assertJsonPath('workspaces.0.plan.slug', 'professional');
        $this->withToken($token)->postJson('/api/extension/token', ['extension_id' => str_repeat('a', 32)])->assertForbidden();

        $workspace->update(['plan_id' => Plan::where('slug', 'free')->firstOrFail()->id]);
        $this->withToken($token)->getJson('/api/extension/context')
            ->assertOk()->assertJsonPath('workspaces.0.access.allowed', false);
    }

    public function test_extension_batch_save_requires_membership_subscription_and_matching_list(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $list = $workspace->lists()->create(['name' => 'Extension leads']);
        $otherUser = User::factory()->create();
        $otherWorkspace = $this->workspace($otherUser);
        $otherList = $otherWorkspace->lists()->create(['name' => 'Private']);
        $token = $user->createToken('test', ['extension:read', 'extension:write'], now()->addMinutes(15))->plainTextToken;
        $payload = ['list_id' => $list->id, 'leads' => [['name' => 'Acme HVAC', 'place_id' => 'place-1', 'website' => 'https://acme.test']]];

        $this->withToken($token)->postJson('/api/extension/workspaces/'.$workspace->id.'/leads', $payload)
            ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('saved', 1);
        $this->withToken($token)->postJson('/api/extension/workspaces/'.$workspace->id.'/leads', $payload)
            ->assertOk()->assertJsonPath('created', 0)->assertJsonPath('existing', 1);
        $this->withToken($token)->postJson('/api/extension/workspaces/'.$workspace->id.'/leads', array_merge($payload, ['list_id' => $otherList->id]))->assertNotFound();
        $this->withToken($token)->postJson('/api/extension/workspaces/'.$otherWorkspace->id.'/leads', array_merge($payload, ['list_id' => $otherList->id]))->assertNotFound();
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('lead_list_items', ['lead_list_id' => $list->id]);

        $workspace->update(['plan_id' => Plan::where('slug', 'free')->firstOrFail()->id]);
        $this->withToken($token)->postJson('/api/extension/workspaces/'.$workspace->id.'/leads', $payload)->assertStatus(402);
    }

    public function test_database_lead_persistence_search_deduplication_and_plan_limit(): void
    {
        $u = User::factory()->create();
        $w = $this->workspace($u);
        $this->actingAs($u);
        $path = '/api/workspaces/'.$w->id.'/leads';
        $this->postJson($path, ['name' => 'Coffee Shop', 'website' => 'https://www.coffee.test', 'city' => 'Austin'])->assertCreated();
        $this->postJson($path, ['name' => 'Coffee Duplicate', 'website' => 'https://coffee.test/menu'])->assertUnprocessable();
        $this->getJson($path.'?q=Austin')->assertJsonPath('total', 1);
        $this->getJson($path.'?q=London')->assertJsonPath('total', 0);
        $w->plan->update(['limits' => array_merge($w->plan->limits, ['leads' => 1])]);
        $this->postJson($path, ['name' => 'Over quota'])->assertUnprocessable();
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_admin_changes_are_audited_and_self_demotion_is_prevented(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin);
        $this->patchJson('/api/admin/users/'.$admin->id, ['is_super_admin' => false])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$user->id, ['is_super_admin' => true])->assertOk()->assertJsonPath('is_super_admin', true);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'user.access_updated', 'resource_id' => $user->id]);
        $this->getJson('/api/admin/overview')->assertOk()->assertJsonPath('users', 2);
        $this->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_suspended_users_cannot_login_or_continue_a_session(): void
    {
        $u = User::factory()->create(['password' => 'StrongPassword123']);
        $u->forceFill(['suspended_at' => now()])->save();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'StrongPassword123'])->assertUnprocessable();
        $this->actingAs($u)->getJson('/api/me')->assertForbidden();
    }

    public function test_admin_can_suspend_workspace_and_change_entitlements(): void
    {
        $admin = $this->admin();
        $u = User::factory()->create();
        $w = $this->workspace($u);
        $p = Plan::where('slug', 'professional')->first();
        $this->actingAs($admin)->patchJson('/api/admin/workspaces/'.$w->id, ['plan_id' => $p->id, 'suspended' => true])->assertOk();
        $this->assertDatabaseHas('workspaces', ['id' => $w->id, 'plan_id' => $p->id]);
        $this->actingAs($u)->getJson('/api/workspaces/'.$w->id.'/leads')->assertForbidden();
    }

    public function test_plan_changes_validate_and_preserve_unspecified_limits(): void
    {
        $p = Plan::first();
        $this->actingAs($this->admin());
        $this->patchJson('/api/admin/plans/'.$p->id, ['monthly_price_cents' => -1])->assertUnprocessable();
        $this->patchJson('/api/admin/plans/'.$p->id, ['limits' => ['leads' => 999]])->assertOk()->assertJsonPath('limits.leads', 999)->assertJsonPath('limits.members', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated']);
    }

    public function test_login_logout_and_throttling(): void
    {
        $u = User::factory()->create(['password' => 'StrongPassword123']);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'StrongPassword123'])->assertOk()->assertJsonMissingPath('password');
        $this->postJson('/api/logout')->assertOk();
        // Clear the cached guard to simulate the next HTTP request.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong']);
        }
        $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_overview_uses_real_workspace_counts_and_reporting_period(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $other = $this->workspace(User::factory()->create());
        $other->leads()->create(['name' => 'Private business', 'category' => 'Private category']);
        $this->actingAs($user);
        $path = '/api/workspaces/'.$workspace->id.'/summary';
        $this->getJson($path)->assertOk()->assertJsonPath('leads', 0)->assertJsonPath('added', 0)
            ->assertJsonCount(30, 'growth')->assertJsonCount(0, 'recent_leads')->assertJsonCount(0, 'categories');
        $workspace->leads()->create(['name' => 'New cafe', 'category' => 'Coffee shop', 'country' => 'Canada', 'enriched_at' => now()]);
        $old = $workspace->leads()->create(['name' => 'Older cafe', 'category' => 'Coffee shop', 'country' => 'Canada']);
        $old->forceFill(['created_at' => now()->subDays(12)])->save();
        $workspace->lists()->create(['name' => 'My collection']);
        $this->getJson($path.'?days=7')->assertOk()->assertJsonPath('leads', 2)->assertJsonPath('added', 1)
            ->assertJsonPath('enriched', 1)->assertJsonPath('lists', 1)->assertJsonCount(7, 'growth')
            ->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.total', 2)
            ->assertJsonCount(1, 'countries')->assertJsonPath('countries.0.name', 'Canada')->assertJsonPath('countries.0.total', 2)
            ->assertJsonPath('recent_leads.0.name', 'New cafe')->assertJsonPath('recent_lists.0.name', 'My collection');
        $this->getJson($path.'?days=30')->assertJsonPath('added', 2);
        $this->getJson($path.'?days=999')->assertUnprocessable();
    }

    public function test_lead_filters_pagination_and_scoped_bulk_delete(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $other = $this->workspace(User::factory()->create());
        $keep = $workspace->leads()->create(['name' => 'Keep cafe', 'category' => 'Cafe', 'country' => 'Canada', 'average_rating' => 4.9, 'email' => null]);
        $delete = $workspace->leads()->create(['name' => 'Delete agency', 'category' => 'Agency', 'country' => 'Canada', 'average_rating' => 4.6, 'email' => 'hello@example.test']);
        $foreign = $other->leads()->create(['name' => 'Foreign agency', 'category' => 'Agency', 'country' => 'Canada', 'average_rating' => 5, 'email' => 'private@example.test']);
        $this->actingAs($user);

        $path = '/api/workspaces/'.$workspace->id.'/leads';
        $this->getJson($path.'?category=Agency&country=Canada&min_rating=4.5&email_status=with_email&per_page=30')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $delete->id);
        $this->deleteJson($path, ['mode' => 'filtered', 'category' => 'Agency', 'country' => 'Canada', 'min_rating' => 4.5, 'email_status' => 'with_email'])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseHas('leads', ['id' => $keep->id]);
        $this->assertDatabaseHas('leads', ['id' => $foreign->id]);
        $this->assertDatabaseMissing('leads', ['id' => $delete->id]);
    }

    public function test_selected_leads_can_be_added_to_a_workspace_list(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $lead = $workspace->leads()->create(['name' => 'Selected business', 'email' => 'selected@example.test']);
        $list = $workspace->lists()->create(['name' => 'Outreach']);

        $this->actingAs($user)->postJson('/api/workspaces/'.$workspace->id.'/lists/'.$list->id.'/leads', ['lead_ids' => [$lead->id]])
            ->assertOk()->assertJson(['added' => 1, 'total' => 1]);
        $this->assertDatabaseHas('lead_list_items', ['lead_list_id' => $list->id, 'lead_id' => $lead->id]);
    }
}
