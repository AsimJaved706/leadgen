<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlatformSetting;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeGateway
{
    private function client(): StripeClient
    {
        $secret = $this->secret();
        abort_unless($secret, 503, 'Stripe billing is not configured. Ask a super administrator to configure Stripe.');

        return new StripeClient($secret);
    }

    public function secret(): ?string
    {
        return PlatformSetting::query()->first()?->stripe_secret ?: config('services.stripe.secret');
    }

    public function webhookSecret(): ?string
    {
        return PlatformSetting::query()->first()?->stripe_webhook_secret ?: config('services.stripe.webhook_secret');
    }

    public function configured(): bool
    {
        return (bool) $this->secret();
    }

    public function automaticTax(): bool
    {
        $settings = PlatformSetting::query()->first();

        return $settings ? $settings->stripe_automatic_tax : (bool) config('services.stripe.automatic_tax');
    }

    public function frontendUrl(): string
    {
        $value = PlatformSetting::query()->first()?->frontend_url ?: config('services.stripe.frontend_url', 'http://localhost:5173');
        $parts = parse_url($value);
        abort_unless(isset($parts['scheme'], $parts['host']), 500, 'The application URL is invalid.');

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function account(): object
    {
        return $this->client()->accounts->retrieve();
    }

    public function createPlanPrices(Plan $plan, string $currency = 'usd'): Plan
    {
        $client = $this->client();
        $product = $client->products->create([
            'name' => 'Leadspace '.$plan->name,
            'description' => $plan->name.' workspace subscription',
            'metadata' => ['leadspace_plan_id' => (string) $plan->id, 'leadspace_plan_slug' => $plan->slug],
        ], ['idempotency_key' => 'leadspace-product-'.$plan->id.'-'.$currency]);
        if (! $plan->stripe_monthly_price_id) {
            $price = $client->prices->create([
                'product' => $product->id,
                'currency' => $currency,
                'unit_amount' => $plan->monthly_price_cents,
                'recurring' => ['interval' => 'month'],
                'metadata' => ['leadspace_plan_id' => (string) $plan->id],
            ], ['idempotency_key' => 'leadspace-price-'.$plan->id.'-month-'.$currency]);
            $plan->update(['stripe_monthly_price_id' => $price->id]);
        }
        if (! $plan->stripe_yearly_price_id) {
            $price = $client->prices->create([
                'product' => $product->id,
                'currency' => $currency,
                'unit_amount' => $plan->yearly_price_cents,
                'recurring' => ['interval' => 'year'],
                'metadata' => ['leadspace_plan_id' => (string) $plan->id],
            ], ['idempotency_key' => 'leadspace-price-'.$plan->id.'-year-'.$currency]);
            $plan->update(['stripe_yearly_price_id' => $price->id]);
        }

        return $plan->refresh();
    }

    public function replacePlanPrices(Plan $plan, string $currency): Plan
    {
        $client = $this->client();
        $productIds = [];
        foreach (array_filter([$plan->stripe_monthly_price_id, $plan->stripe_yearly_price_id]) as $priceId) {
            $price = $client->prices->retrieve($priceId, []);
            $productIds[] = (string) $price->product;
            if ($price->active) {
                $client->prices->update($priceId, ['active' => false]);
            }
        }
        foreach (array_unique($productIds) as $productId) {
            $client->products->update($productId, ['active' => false]);
        }
        $plan->update(['stripe_monthly_price_id' => null, 'stripe_yearly_price_id' => null]);

        return $this->createPlanPrices($plan->refresh(), strtolower($currency));
    }

    public function createCheckoutSession(array $parameters): object
    {
        return $this->client()->checkout->sessions->create($parameters);
    }

    public function createPortalSession(array $parameters): object
    {
        return $this->client()->billingPortal->sessions->create($parameters);
    }

    public function retrieveSubscription(string $id): object
    {
        return $this->client()->subscriptions->retrieve($id, []);
    }

    public function retrieveCheckoutSession(string $id): object
    {
        return $this->client()->checkout->sessions->retrieve($id, ['expand' => ['subscription']]);
    }

    public function constructEvent(string $payload, string $signature): object
    {
        $secret = $this->webhookSecret();
        abort_unless($secret, 503, 'Stripe webhook signing is not configured.');

        return Webhook::constructEvent($payload, $signature, $secret);
    }
}
