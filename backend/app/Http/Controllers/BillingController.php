<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Workspace;
use App\Services\StripeGateway;
use App\Services\StripeSubscriptionSync;
use App\Support\Audit;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private function member(Request $request, Workspace $workspace, bool $manage = false)
    {
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($workspace->suspended_at, 403, 'This workspace is suspended.');
        if ($manage) {
            abort_unless(in_array($member->pivot->role, ['owner', 'admin'], true), 403, 'Only workspace owners and admins can manage billing.');
        }

        return $member;
    }

    public function status(Request $request, Workspace $workspace, StripeGateway $stripe)
    {
        $member = $this->member($request, $workspace);
        // Legacy/admin-granted entitlements are not Stripe subscriptions.
        $subscription = $workspace->currentSubscription()->whereNotNull('provider_id')->with('plan')->first();

        return [
            'workspace' => $workspace->load('plan'),
            'subscription' => $subscription,
            'plans' => Plan::where('is_active', true)->orderBy('monthly_price_cents')->get(),
            'can_manage' => in_array($member->pivot->role, ['owner', 'admin'], true),
            'stripe_configured' => $stripe->configured(),
        ];
    }

    public function checkout(Request $request, Workspace $workspace, StripeGateway $stripe)
    {
        $this->member($request, $workspace, true);
        $data = $request->validate(['plan_id' => 'required|integer|exists:plans,id', 'interval' => 'required|in:month,year']);
        $plan = Plan::whereKey($data['plan_id'])->where('is_active', true)->firstOrFail();
        abort_if($plan->slug === 'free', 422, 'The free plan does not require checkout.');
        $current = $workspace->currentSubscription()->whereNotNull('provider_id')->first();
        abort_if($current && in_array($current->status, ['active', 'trialing', 'past_due'], true), 422, 'Manage your existing subscription in the billing portal.');
        $priceId = $data['interval'] === 'year' ? $plan->stripe_yearly_price_id : $plan->stripe_monthly_price_id;
        abort_unless($priceId, 422, 'This plan is not connected to a Stripe price yet.');
        $frontend = rtrim($stripe->frontendUrl(), '/');
        $parameters = [
            'mode' => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $frontend.'/app?billing=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend.'/app?billing=cancelled',
            'client_reference_id' => (string) $workspace->id,
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
            'tax_id_collection' => ['enabled' => true],
            'metadata' => ['workspace_id' => (string) $workspace->id, 'plan_id' => (string) $plan->id, 'user_id' => (string) $request->user()->id],
            'subscription_data' => ['metadata' => ['workspace_id' => (string) $workspace->id, 'plan_id' => (string) $plan->id, 'user_id' => (string) $request->user()->id]],
        ];
        if ($stripe->automaticTax()) {
            $parameters['automatic_tax'] = ['enabled' => true];
        }
        if ($current?->provider_customer_id) {
            $parameters['customer'] = $current->provider_customer_id;
        } else {
            $parameters['customer_email'] = $request->user()->email;
        }
        $session = $stripe->createCheckoutSession($parameters);
        Audit::record('billing.checkout_started', $plan->id, ['interval' => $data['interval']], $workspace->id);

        return ['url' => $session->url];
    }

    public function portal(Request $request, Workspace $workspace, StripeGateway $stripe)
    {
        $this->member($request, $workspace, true);
        $subscription = $workspace->currentSubscription()->whereNotNull('provider_id')->first();
        abort_unless($subscription?->provider_customer_id, 422, 'No Stripe customer exists for this workspace.');
        $session = $stripe->createPortalSession(['customer' => $subscription->provider_customer_id, 'return_url' => rtrim($stripe->frontendUrl(), '/').'/app']);

        return ['url' => $session->url];
    }

    public function confirm(Request $request, Workspace $workspace, StripeGateway $stripe, StripeSubscriptionSync $sync)
    {
        $this->member($request, $workspace, true);
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255', 'regex:/^cs_(test_|live_)?[A-Za-z0-9]+$/']]);
        $session = $stripe->retrieveCheckoutSession($data['session_id']);
        abort_unless($session->status === 'complete', 422, 'Stripe Checkout is not complete.');
        abort_unless((int) ($session->metadata?->workspace_id ?? 0) === $workspace->id, 403, 'Checkout does not belong to this workspace.');
        abort_unless((int) ($session->metadata?->user_id ?? 0) === $request->user()->id, 403, 'Checkout does not belong to this user.');
        abort_unless($session->subscription, 422, 'Stripe did not create a subscription.');
        $subscription = is_string($session->subscription) ? $stripe->retrieveSubscription($session->subscription) : $session->subscription;
        $sync->sync($subscription);

        return ['confirmed' => true, 'plan' => $workspace->fresh()->plan];
    }
}
