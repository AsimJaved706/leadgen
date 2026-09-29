<?php

namespace App\Http\Controllers;

use App\Services\StripeGateway;
use App\Services\StripeSubscriptionSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe, StripeSubscriptionSync $sync)
    {
        try {
            $event = $stripe->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid Stripe webhook.'], 400);
        }
        $record = DB::table('stripe_webhook_events')->where('event_id', $event->id)->first();
        if ($record?->processed_at) {
            return ['received' => true];
        }
        DB::table('stripe_webhook_events')->updateOrInsert(['event_id' => $event->id], ['type' => $event->type, 'updated_at' => now(), 'created_at' => $record?->created_at ?? now()]);
        try {
            $object = $event->data->object;
            if (in_array($event->type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
                $sync->sync($object);
            } elseif ($event->type === 'checkout.session.completed' && ($object->subscription ?? null)) {
                $sync->sync($stripe->retrieveSubscription((string) $object->subscription));
            } elseif (in_array($event->type, ['invoice.paid', 'invoice.payment_failed'], true)) {
                $subscriptionId = $object->subscription ?? $object->parent?->subscription_details?->subscription ?? null;
                if ($subscriptionId) {
                    $sync->sync($stripe->retrieveSubscription((string) $subscriptionId));
                }
            }
            DB::table('stripe_webhook_events')->where('event_id', $event->id)->update(['processed_at' => now(), 'failure_reason' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('stripe_webhook_events')->where('event_id', $event->id)->update(['failure_reason' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            report($e);

            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }

        return ['received' => true];
    }
}
