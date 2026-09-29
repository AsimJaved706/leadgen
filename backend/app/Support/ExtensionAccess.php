<?php

namespace App\Support;

use App\Models\Workspace;

class ExtensionAccess
{
    public static function status(Workspace $workspace): array
    {
        $workspace->loadMissing('plan', 'currentSubscription');
        $subscription = $workspace->currentSubscription;
        $activeStatuses = ['active', 'trialing'];
        $periodActive = ! $subscription?->current_period_end || $subscription->current_period_end->isFuture();
        $legacyOrAdminGrant = ! $subscription || ! $subscription->provider_id;
        $subscriptionActive = $legacyOrAdminGrant || (in_array($subscription->status, $activeStatuses, true) && $periodActive);
        $allowed = ! $workspace->suspended_at && $workspace->plan->slug !== 'free' && $subscriptionActive;

        return [
            'allowed' => $allowed,
            'reason' => $workspace->suspended_at ? 'Workspace access is suspended.'
                : ($workspace->plan->slug === 'free' ? 'A paid subscription is required.'
                : (! $subscriptionActive ? 'The workspace subscription is inactive or expired.' : null)),
            'subscription' => [
                'status' => $subscription?->status ?? ($legacyOrAdminGrant ? 'admin_granted' : 'inactive'),
                'current_period_end' => $subscription?->current_period_end?->toISOString(),
            ],
        ];
    }
}
