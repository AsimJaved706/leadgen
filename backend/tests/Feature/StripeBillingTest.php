<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StripeGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.stripe.secret' => 'sk_test_configured']);
    }

    private function workspace(User $user, string $role = 'owner'): Workspace
    {
        $workspace = Workspace::create([
            'name' => 'Billing workspace',
            'owner_id' => $user->id,
            'plan_id' => Plan::where('slug', 'free')->firstOrFail()->id,
        ]);
        $workspace->members()->attach($user, ['role' => $role]);

        return $workspace;
    }

    private function stripeSubscription(Workspace $workspace, Plan $plan, string $status = 'active'): object
    {
        return json_decode(json_encode([
            'id' => 'sub_test_123',
            'customer' => 'cus_test_123',
            'status' => $status,
            'metadata' => ['workspace_id' => (string) $workspace->id, 'plan_id' => (string) $plan->id],
            'items' => ['data' => [[
                'price' => ['id' => $plan->stripe_monthly_price_id, 'recurring' => ['interval' => 'month']],
                'current_period_start' => now()->subDay()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
            ]]],
            'trial_end' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => $status === 'canceled' ? now()->timestamp : null,
        ]));
    }

    public function test_owner_can_start_checkout_with_server_controlled_price_and_metadata(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_professional_monthly']);
        $gateway = $this->mock(StripeGateway::class);
        $gateway->shouldReceive('frontendUrl')->once()->andReturn('http://localhost:5173');
        $gateway->shouldReceive('automaticTax')->once()->andReturn(false);
        $gateway->shouldReceive('createCheckoutSession')->once()->with(Mockery::on(function (array $parameters) use ($workspace, $plan, $owner) {
            return $parameters['mode'] === 'subscription'
                && $parameters['line_items'][0]['price'] === 'price_professional_monthly'
                && $parameters['success_url'] === 'http://localhost:5173/app?billing=success&session_id={CHECKOUT_SESSION_ID}'
                && $parameters['cancel_url'] === 'http://localhost:5173/app?billing=cancelled'
                && $parameters['metadata']['workspace_id'] === (string) $workspace->id
                && $parameters['metadata']['user_id'] === (string) $owner->id
                && $parameters['subscription_data']['metadata']['plan_id'] === (string) $plan->id;
        }))->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

        $this->actingAs($owner)->postJson('/api/workspaces/'.$workspace->id.'/billing/checkout', [
            'plan_id' => $plan->id,
            'interval' => 'month',
        ])->assertOk()->assertJsonPath('url', 'https://checkout.stripe.test/session');
    }

    public function test_viewer_cannot_manage_billing(): void
    {
        $viewer = User::factory()->create();
        $workspace = $this->workspace($viewer, 'viewer');
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_professional_monthly']);

        $this->actingAs($viewer)->postJson('/api/workspaces/'.$workspace->id.'/billing/checkout', [
            'plan_id' => $plan->id,
            'interval' => 'month',
        ])->assertForbidden();
    }

    public function test_legacy_admin_entitlement_is_not_reported_as_a_stripe_subscription(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $workspace->update(['plan_id' => $plan->id]);
        $workspace->subscriptions()->create(['plan_id' => $plan->id, 'provider_id' => null, 'status' => 'active']);

        $this->actingAs($owner)->getJson('/api/workspaces/'.$workspace->id.'/billing')
            ->assertOk()->assertJsonPath('workspace.plan.slug', 'professional')->assertJsonPath('subscription', null);
    }

    public function test_signed_subscription_webhook_updates_entitlements_and_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_professional_monthly']);
        $subscription = $this->stripeSubscription($workspace, $plan);
        $event = (object) ['id' => 'evt_subscription_active', 'type' => 'customer.subscription.updated', 'data' => (object) ['object' => $subscription]];
        $gateway = $this->mock(StripeGateway::class);
        $gateway->shouldReceive('constructEvent')->twice()->andReturn($event);

        $this->postJson('/api/stripe/webhook', [], ['Stripe-Signature' => 'valid'])->assertOk();
        $this->postJson('/api/stripe/webhook', [], ['Stripe-Signature' => 'valid'])->assertOk();

        $this->assertDatabaseHas('subscriptions', ['provider_id' => 'sub_test_123', 'workspace_id' => $workspace->id, 'status' => 'active']);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'plan_id' => $plan->id]);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_completed_checkout_can_be_confirmed_for_its_authenticated_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_professional_monthly']);
        $stripeSubscription = $this->stripeSubscription($workspace, $plan);
        $checkout = json_decode(json_encode([
            'id' => 'cs_test_completed123', 'status' => 'complete',
            'metadata' => ['workspace_id' => (string) $workspace->id, 'user_id' => (string) $owner->id],
            'subscription' => $stripeSubscription,
        ]));
        $gateway = $this->mock(StripeGateway::class);
        $gateway->shouldReceive('retrieveCheckoutSession')->once()->with('cs_test_completed123')->andReturn($checkout);

        $this->actingAs($owner)->postJson('/api/workspaces/'.$workspace->id.'/billing/confirm', ['session_id' => 'cs_test_completed123'])
            ->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('plan.slug', 'professional');
        $this->assertDatabaseHas('subscriptions', ['workspace_id' => $workspace->id, 'provider_id' => 'sub_test_123']);
    }

    public function test_canceled_subscription_webhook_returns_workspace_to_free_plan(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $plan = Plan::where('slug', 'professional')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_professional_monthly']);
        $workspace->update(['plan_id' => $plan->id]);
        $event = (object) [
            'id' => 'evt_subscription_canceled',
            'type' => 'customer.subscription.deleted',
            'data' => (object) ['object' => $this->stripeSubscription($workspace, $plan, 'canceled')],
        ];
        $gateway = $this->mock(StripeGateway::class);
        $gateway->shouldReceive('constructEvent')->once()->andReturn($event);

        $this->postJson('/api/stripe/webhook', [], ['Stripe-Signature' => 'valid'])->assertOk();

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'plan_id' => Plan::where('slug', 'free')->firstOrFail()->id]);
    }

    public function test_invalid_webhook_signature_is_rejected_without_writes(): void
    {
        $gateway = $this->mock(StripeGateway::class);
        $gateway->shouldReceive('constructEvent')->once()->andThrow(new \UnexpectedValueException('Bad signature'));

        $this->postJson('/api/stripe/webhook', [], ['Stripe-Signature' => 'bad'])->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_super_admin_can_manage_encrypted_stripe_settings_without_secrets_being_returned(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $response = $this->actingAs($admin)->patchJson('/api/admin/stripe', [
            'mode' => 'test',
            'secret' => 'sk_test_example123456',
            'webhook_secret' => 'whsec_example123456',
            'automatic_tax' => true,
            'frontend_url' => 'https://app.example.test',
        ])->assertOk()->assertJsonPath('has_secret', true)->assertJsonPath('has_webhook_secret', true);

        $response->assertJsonMissing(['stripe_secret', 'stripe_webhook_secret', 'sk_test_example123456', 'whsec_example123456']);
        $stored = DB::table('platform_settings')->first();
        $this->assertNotSame('sk_test_example123456', $stored->stripe_secret);
        $this->assertNotSame('whsec_example123456', $stored->stripe_webhook_secret);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stripe.settings_updated']);
        $this->getJson('/api/admin/stripe')->assertOk()->assertJsonMissing(['sk_test_example123456', 'whsec_example123456']);
    }

    public function test_non_admin_cannot_read_or_change_stripe_settings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/stripe')->assertForbidden();
        $this->patchJson('/api/admin/stripe', [])->assertForbidden();
    }
}
