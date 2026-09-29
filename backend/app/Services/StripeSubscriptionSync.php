<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class StripeSubscriptionSync
{
    public function sync(object $stripeSubscription): Subscription
    {
        $providerId = (string) $stripeSubscription->id;
        $metadata = $stripeSubscription->metadata ?? null;
        $workspaceId = (int) ($metadata?->workspace_id ?? 0);
        $item = $stripeSubscription->items->data[0] ?? null;
        $priceId = (string) ($item?->price?->id ?? '');
        $plan = Plan::where('stripe_monthly_price_id', $priceId)->orWhere('stripe_yearly_price_id', $priceId)->first();
        if (! $plan && isset($metadata?->plan_id)) {
            $plan = Plan::find((int) $metadata->plan_id);
        }
        $existing = Subscription::where('provider_id', $providerId)->first();
        $workspace = $workspaceId ? Workspace::find($workspaceId) : $existing?->workspace;
        if (! $workspace || ! $plan) {
            throw new \RuntimeException('Stripe subscription cannot be matched to a workspace and plan.');
        }

        $status = (string) $stripeSubscription->status;
        $periodStart = $stripeSubscription->current_period_start ?? $item?->current_period_start ?? null;
        $periodEnd = $stripeSubscription->current_period_end ?? $item?->current_period_end ?? null;
        $active = in_array($status, ['active', 'trialing', 'past_due'], true) && (! $periodEnd || $periodEnd > time());

        return DB::transaction(function () use ($workspace, $plan, $providerId, $stripeSubscription, $priceId, $item, $status, $periodStart, $periodEnd, $active) {
            $subscription = Subscription::updateOrCreate(['provider_id' => $providerId], [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'provider_customer_id' => (string) $stripeSubscription->customer,
                'provider_price_id' => $priceId,
                'billing_interval' => $item?->price?->recurring?->interval,
                'status' => $status,
                'trial_ends_at' => ($stripeSubscription->trial_end ?? null) ? now()->setTimestamp($stripeSubscription->trial_end) : null,
                'current_period_start' => $periodStart ? now()->setTimestamp($periodStart) : null,
                'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
                'ends_at' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
                'cancel_at_period_end' => (bool) ($stripeSubscription->cancel_at_period_end ?? false),
                'canceled_at' => ($stripeSubscription->canceled_at ?? null) ? now()->setTimestamp($stripeSubscription->canceled_at) : null,
            ]);
            $targetPlan = $active ? $plan : Plan::where('slug', 'free')->firstOrFail();
            if ($workspace->plan_id !== $targetPlan->id) {
                $workspace->update(['plan_id' => $targetPlan->id]);
            }
            Audit::record('subscription.synced', $subscription->id, ['status' => $status, 'plan' => $targetPlan->slug], $workspace->id);

            return $subscription->load('plan');
        });
    }
}
