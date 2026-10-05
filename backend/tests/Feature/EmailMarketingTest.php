<?php

namespace Tests\Feature;

use App\Jobs\PrepareEmailCampaign;
use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailUnsubscribe;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailMarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function workspace(User $user, string $role = 'owner'): Workspace
    {
        $workspace = Workspace::create(['name' => 'Email workspace', 'owner_id' => $user->id, 'plan_id' => Plan::where('slug', 'professional')->firstOrFail()->id]);
        $workspace->members()->attach($user, ['role' => $role]);

        return $workspace;
    }

    private function smtp(Workspace $workspace): void
    {
        $workspace->emailSetting()->create(['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'api', 'password' => 'SecretPassword', 'from_email' => 'sender@example.test', 'from_name' => 'Sender', 'is_active' => true]);
    }

    public function test_smtp_password_is_encrypted_hidden_and_scoped(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $other = $this->workspace(User::factory()->create());
        $payload = ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'VerySecretPassword', 'from_email' => 'sender@example.test', 'from_name' => 'Acme', 'reply_to_email' => 'reply@example.test', 'is_active' => true];
        $this->actingAs($owner)->putJson('/api/workspaces/'.$workspace->id.'/email-settings', $payload)->assertOk()->assertJsonPath('has_password', true)->assertJsonMissingPath('password');
        $this->assertNotSame('VerySecretPassword', DB::table('email_settings')->value('password'));
        $this->getJson('/api/workspaces/'.$workspace->id.'/email-settings')->assertOk()->assertJsonMissingPath('password');
        $this->getJson('/api/workspaces/'.$other->id.'/email-settings')->assertNotFound();
    }

    public function test_gmail_app_password_spaces_are_removed_before_encryption(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $payload = ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'sender@gmail.com', 'password' => 'abcd efgh ijkl mnop', 'from_email' => 'sender@gmail.com', 'from_name' => 'Sender', 'is_active' => true];

        $this->actingAs($owner)->putJson('/api/workspaces/'.$workspace->id.'/email-settings', $payload)->assertOk()->assertJsonPath('has_password', true);
        $this->assertSame('abcdefghijklmnop', $workspace->fresh()->emailSetting->password);
    }

    public function test_viewers_cannot_change_email_marketing_and_smtp_requires_admin(): void
    {
        $viewer = User::factory()->create();
        $workspace = $this->workspace($viewer, 'viewer');
        $this->actingAs($viewer);
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-templates', ['name' => 'Intro', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>'])->assertForbidden();
        $this->putJson('/api/workspaces/'.$workspace->id.'/email-settings', [])->assertForbidden();
        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->putJson('/api/workspaces/'.$workspace->id.'/email-settings', [])->assertForbidden();
    }

    public function test_templates_are_sanitized_and_cross_workspace_ids_are_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $other = $this->workspace(User::factory()->create());
        $this->actingAs($owner);
        $response = $this->postJson('/api/workspaces/'.$workspace->id.'/email-templates', ['name' => 'Introduction', 'subject' => 'Hello {{lead.name}}', 'html_body' => '<h1 onclick="steal()">Hello</h1><script>steal()</script>'])->assertCreated();
        $this->assertStringNotContainsString('script', $response->json('html_body'));
        $this->assertStringNotContainsString('onclick', $response->json('html_body'));
        $foreign = $other->emailTemplates()->create(['name' => 'Foreign', 'subject' => 'No', 'html_body' => '<p>No</p>']);
        $this->putJson('/api/workspaces/'.$workspace->id.'/email-templates/'.$foreign->id, ['name' => 'Stolen', 'subject' => 'No', 'html_body' => '<p>No</p>'])->assertNotFound();
    }

    public function test_full_stack_application_template_is_installed_for_workspaces(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        \App\Support\ProfessionalEmailTemplates::install($workspace);
        $template = $workspace->emailTemplates()->where('name', 'Full Stack Developer Application')->firstOrFail();
        $this->assertSame('Application for Senior Full Stack Developer - Fahad Tanwir', $template->subject);
        $this->assertStringContainsString('more than eight years', $template->text_body);
        $this->assertStringContainsString('fahadm.dev@gmail.com', $template->html_body);
        $this->assertTrue($template->is_active);
    }

    public function test_campaign_snapshots_valid_unique_subscribed_recipients_and_queues_delivery(): void
    {
        Queue::fake([SendCampaignEmail::class]);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $this->smtp($workspace);
        $template = $workspace->emailTemplates()->create(['name' => 'Intro', 'subject' => 'Hello {{lead.name}}', 'html_body' => '<p>Hello {{lead.name}}</p>']);
        $one = $workspace->leads()->create(['name' => 'One', 'email' => 'ONE@example.test']);
        $workspace->leads()->create(['name' => 'Duplicate', 'email' => 'one@example.test']);
        $workspace->leads()->create(['name' => 'Invalid', 'email' => 'not-an-email']);
        $workspace->leads()->create(['name' => 'Blocked', 'email' => 'blocked@example.test']);
        EmailUnsubscribe::create(['workspace_id' => $workspace->id, 'email' => 'blocked@example.test', 'unsubscribed_at' => now()]);
        $campaign = $workspace->emailCampaigns()->create(['name' => 'Launch', 'email_template_id' => $template->id, 'created_by' => $owner->id, 'audience_type' => 'all', 'status' => 'scheduled', 'scheduled_at' => now()]);
        (new PrepareEmailCampaign($campaign->id))->handle();
        $this->assertDatabaseCount('email_campaign_recipients', 1);
        $this->assertDatabaseHas('email_campaign_recipients', ['lead_id' => $one->id, 'email' => 'one@example.test']);
        $this->assertDatabaseHas('email_campaigns', ['id' => $campaign->id, 'status' => 'sending', 'recipient_count' => 1]);
        $this->assertDatabaseHas('usage_records', ['workspace_id' => $workspace->id, 'metric' => 'emails_queued', 'quantity' => 1]);
        Queue::assertPushed(SendCampaignEmail::class, 1);
    }

    public function test_campaign_creation_supports_draft_now_and_future_schedule(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $template = $workspace->emailTemplates()->create(['name' => 'Intro', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $this->actingAs($owner);
        $base = ['name' => 'Draft', 'email_template_id' => $template->id, 'audience_type' => 'all', 'lead_list_id' => null];
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns', $base + ['send_mode' => 'draft'])->assertCreated()->assertJsonPath('status', 'draft');
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns', $base + ['name' => 'Now', 'send_mode' => 'now'])->assertUnprocessable();
        $this->smtp($workspace);
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns', $base + ['name' => 'Now', 'send_mode' => 'now'])->assertCreated()->assertJsonPath('status', 'scheduled');
        Queue::assertPushed(PrepareEmailCampaign::class);
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns', $base + ['name' => 'Future', 'send_mode' => 'schedule', 'scheduled_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $draft = EmailCampaign::where('name', 'Draft')->firstOrFail();
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns/'.$draft->id.'/send')->assertOk()->assertJsonPath('status', 'scheduled');
        Queue::assertPushed(PrepareEmailCampaign::class, 2);
        $draft->update(['status' => 'cancelled']);
        $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns/'.$draft->id.'/send')->assertOk()->assertJsonPath('status', 'scheduled');
        Queue::assertPushed(PrepareEmailCampaign::class, 3);
    }

    public function test_country_email_group_can_be_created_and_used_for_campaign(): void
    {
        Queue::fake([SendCampaignEmail::class]);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $this->smtp($workspace);
        $template = $workspace->emailTemplates()->create(['name' => 'Countries', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $canada = $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'Canada Developer', 'company_name' => 'Canada Co', 'country' => 'Canada', 'contact_email' => 'ca@example.test', 'dedupe_hash' => hash('sha256', 'ca')]);
        $workspace->jobs()->create(['source_platform' => 'Indeed', 'title' => 'Canada Missing', 'country' => 'Canada', 'dedupe_hash' => hash('sha256', 'ca-missing')]);
        $usa = $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'USA Developer', 'country' => 'United States', 'contact_email' => 'us@example.test', 'dedupe_hash' => hash('sha256', 'us')]);
        $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'Already applied', 'country' => 'United States', 'contact_email' => 'applied@example.test', 'status' => 'applied', 'dedupe_hash' => hash('sha256', 'applied')]);
        $workspace->jobs()->create(['source_platform' => 'LinkedIn', 'title' => 'UK Developer', 'country' => 'United Kingdom', 'contact_email' => 'uk@example.test', 'dedupe_hash' => hash('sha256', 'uk')]);

        $this->actingAs($owner)->getJson('/api/workspaces/'.$workspace->id.'/campaign-audience-groups')
            ->assertOk()->assertJsonPath('countries.0.country', 'Canada')->assertJsonPath('countries.0.with_email', 1);
        $group = $this->postJson('/api/workspaces/'.$workspace->id.'/campaign-audience-groups', [
            'name' => 'North America with email', 'countries' => ['Canada', 'United States'], 'require_email' => true,
        ])->assertCreated()->assertJsonPath('jobs_count', 2)->json();
        $campaign = $this->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns', [
            'name' => 'Regional campaign', 'email_template_id' => $template->id, 'audience_type' => 'group',
            'campaign_audience_group_id' => $group['id'], 'send_mode' => 'draft',
        ])->assertCreated()->assertJsonPath('audience_group.name', 'North America with email')->json();
        EmailCampaign::findOrFail($campaign['id'])->update(['status' => 'scheduled', 'scheduled_at' => now()]);
        (new PrepareEmailCampaign($campaign['id']))->handle();
        $this->assertDatabaseCount('email_campaign_recipients', 2);
        $this->assertDatabaseHas('email_campaign_recipients', ['job_id' => $canada->id, 'email' => 'ca@example.test']);
        $this->assertDatabaseHas('email_campaign_recipients', ['job_id' => $usa->id, 'email' => 'us@example.test']);
        $this->assertDatabaseMissing('email_campaign_recipients', ['email' => 'uk@example.test']);
        $this->assertDatabaseMissing('email_campaign_recipients', ['email' => 'applied@example.test']);
        EmailCampaignRecipient::where('email', 'ca@example.test')->update(['status' => 'sent', 'sent_at' => now()]);
        $this->getJson('/api/workspaces/'.$workspace->id.'/campaign-audience-groups')
            ->assertOk()->assertJsonPath('groups.0.jobs_count', 1);
    }

    public function test_active_campaign_can_be_stopped(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $template = $workspace->emailTemplates()->create(['name' => 'Active', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $campaign = $workspace->emailCampaigns()->create(['name' => 'Active campaign', 'email_template_id' => $template->id, 'audience_type' => 'all', 'status' => 'sending']);

        $this->actingAs($owner)->postJson('/api/workspaces/'.$workspace->id.'/email-campaigns/'.$campaign->id.'/cancel')
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $workspace->id, 'action' => 'email.campaign_stopped', 'resource_id' => $campaign->id]);
    }

    public function test_campaign_accepts_a_private_document_attachment(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $template = $workspace->emailTemplates()->create(['name' => 'Application', 'subject' => 'Application', 'html_body' => '<p>Attached</p>']);

        $response = $this->actingAs($owner)->post('/api/workspaces/'.$workspace->id.'/email-campaigns', [
            'name' => 'Developer application',
            'email_template_id' => $template->id,
            'audience_type' => 'all',
            'send_mode' => 'draft',
            'attachment' => UploadedFile::fake()->create('resume.pdf', 240, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('attachment_name', 'resume.pdf')->assertJsonMissingPath('attachment_path');
        $campaign = EmailCampaign::firstOrFail();
        Storage::disk('local')->assertExists($campaign->attachment_path);
        $this->assertSame('resume.pdf', $campaign->attachment_name);
    }

    public function test_signed_unsubscribe_suppresses_future_campaigns(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $template = $workspace->emailTemplates()->create(['name' => 'Intro', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $campaign = $workspace->emailCampaigns()->create(['name' => 'Campaign', 'email_template_id' => $template->id, 'audience_type' => 'all', 'status' => 'completed']);
        $recipient = EmailCampaignRecipient::create(['email_campaign_id' => $campaign->id, 'email' => 'lead@example.test', 'name' => 'Lead']);
        $this->get('/email/unsubscribe/'.$recipient->id)->assertForbidden();
        $this->get(URL::signedRoute('email.unsubscribe', ['recipient' => $recipient->id]))->assertOk()->assertSee('unsubscribed');
        $this->assertDatabaseHas('email_unsubscribes', ['workspace_id' => $workspace->id, 'email' => 'lead@example.test']);
    }

    public function test_campaign_recipient_details_and_signed_open_tracking(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $template = $workspace->emailTemplates()->create(['name' => 'Tracking', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $campaign = $workspace->emailCampaigns()->create(['name' => 'Tracked campaign', 'email_template_id' => $template->id, 'audience_type' => 'all', 'status' => 'completed']);
        $recipient = EmailCampaignRecipient::create(['email_campaign_id' => $campaign->id, 'email' => 'lead@example.test', 'name' => 'Lead', 'status' => 'sent', 'sent_at' => now()]);

        $this->get('/email/open/'.$recipient->id.'.gif')->assertForbidden();
        $this->get(URL::signedRoute('email.open', ['recipient' => $recipient->id]))->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertDatabaseHas('email_campaign_recipients', ['id' => $recipient->id, 'open_count' => 1]);
        $this->actingAs($owner)->getJson('/api/workspaces/'.$workspace->id.'/email-campaigns/'.$campaign->id.'/recipients')
            ->assertOk()->assertJsonPath('data.0.email', 'lead@example.test')->assertJsonPath('data.0.open_count', 1);
    }

    public function test_monthly_email_limit_blocks_oversized_campaign_before_queueing(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->plan->update(['limits' => array_merge($workspace->plan->limits, ['monthly_emails' => 1])]);
        $this->smtp($workspace);
        $template = $workspace->emailTemplates()->create(['name' => 'Intro', 'subject' => 'Hello', 'html_body' => '<p>Hello</p>']);
        $workspace->leads()->create(['name' => 'One', 'email' => 'one@example.test']);
        $workspace->leads()->create(['name' => 'Two', 'email' => 'two@example.test']);
        $campaign = $workspace->emailCampaigns()->create(['name' => 'Too large', 'email_template_id' => $template->id, 'audience_type' => 'all', 'status' => 'scheduled', 'scheduled_at' => now()]);

        (new PrepareEmailCampaign($campaign->id))->handle();

        $this->assertDatabaseHas('email_campaigns', ['id' => $campaign->id, 'status' => 'failed']);
        $this->assertDatabaseCount('email_campaign_recipients', 0);
        Queue::assertNotPushed(SendCampaignEmail::class);
    }
}
