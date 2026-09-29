<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StripeGateway;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function overview()
    {
        return ['users' => User::count(), 'workspaces' => Workspace::count(), 'leads' => Lead::count(), 'suspended_users' => User::whereNotNull('suspended_at')->count(), 'recent_users' => User::latest()->limit(5)->get(['id', 'name', 'email', 'created_at']), 'recent_workspaces' => Workspace::with('plan')->withCount('leads', 'members')->latest()->limit(5)->get()];
    }

    public function users(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return User::when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))->withCount('workspaces')->latest()->paginate(15);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['suspended' => 'sometimes|boolean', 'is_super_admin' => 'sometimes|boolean']);
        abort_if($user->id === $request->user()->id, 422, 'You cannot change your own access.');

        return DB::transaction(function () use ($user, $data) {
            // Lock administrators in a stable order before changing privileges.
            $admins = User::where('is_super_admin', true)->orderBy('id')->lockForUpdate()->get();
            $user->refresh();
            if ($user->is_super_admin && (($data['suspended'] ?? false) || (array_key_exists('is_super_admin', $data) && ! $data['is_super_admin']))) {
                abort_if($admins->whereNull('suspended_at')->count() <= 1, 422, 'The platform must retain an active super admin.');
            }
            $before = ['is_super_admin' => $user->is_super_admin, 'suspended' => (bool) $user->suspended_at];
            if (array_key_exists('suspended', $data)) {
                $user->suspended_at = $data['suspended'] ? now() : null;
            }
            if (array_key_exists('is_super_admin', $data)) {
                $user->is_super_admin = $data['is_super_admin'];
            }
            $user->save();
            if ($user->suspended_at) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record('user.access_updated', $user->id, ['before' => $before, 'after' => $data]);

            return $user;
        });
    }

    public function workspaces(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return Workspace::with(['plan', 'owner:id,name,email'])->withCount(['leads', 'members'])->when($data['q'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%$s%"))->latest()->paginate(15);
    }

    public function updateWorkspace(Request $request, Workspace $workspace)
    {
        $data = $request->validate(['suspended' => 'sometimes|boolean', 'plan_id' => 'sometimes|exists:plans,id']);

        return DB::transaction(function () use ($workspace, $data) {
            $workspace = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (isset($data['plan_id'])) {
                $workspace->plan_id = $data['plan_id'];
            }
            if (array_key_exists('suspended', $data)) {
                $workspace->suspended_at = $data['suspended'] ? now() : null;
            }
            $workspace->save();
            Audit::record('workspace.updated', $workspace->id, $data, $workspace->id);

            return $workspace->load('plan');
        });
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'monthly_price_cents' => 'sometimes|integer|min:0|max:100000000', 'yearly_price_cents' => 'sometimes|integer|min:0|max:100000000', 'stripe_monthly_price_id' => 'sometimes|nullable|string|max:255|starts_with:price_', 'stripe_yearly_price_id' => 'sometimes|nullable|string|max:255|starts_with:price_', 'is_active' => 'sometimes|boolean', 'limits' => 'sometimes|array:leads,monthly_imports,members,api_keys,monthly_exports,lists,monthly_emails', 'limits.*' => 'integer|min:1|max:10000000']);
        abort_if($plan->slug === 'free' && isset($data['is_active']) && ! $data['is_active'], 422, 'The registration plan must stay active.');

        return DB::transaction(function () use ($plan, $data) {
            if (isset($data['limits'])) {
                $data['limits'] = array_merge($plan->limits, $data['limits']);
            }
            $plan->update($data);
            Audit::record('plan.updated', $plan->id, $data);

            return $plan;
        });
    }

    public function stripeSettings()
    {
        $settings = PlatformSetting::current();

        return [
            'mode' => $settings->stripe_mode,
            'automatic_tax' => $settings->stripe_automatic_tax,
            'frontend_url' => $settings->frontend_url,
            'has_secret' => (bool) ($settings->stripe_secret ?: config('services.stripe.secret')),
            'has_webhook_secret' => (bool) ($settings->stripe_webhook_secret ?: config('services.stripe.webhook_secret')),
            'secret_source' => $settings->stripe_secret ? 'admin' : (config('services.stripe.secret') ? 'environment' : null),
            'webhook_url' => rtrim($settings->frontend_url, '/').'/api/stripe/webhook',
        ];
    }

    public function updateStripeSettings(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:test,live',
            'secret' => ['nullable', 'string', 'max:500', 'regex:/^sk_(test|live)_[A-Za-z0-9_]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:500', 'starts_with:whsec_'],
            'automatic_tax' => 'required|boolean',
            'frontend_url' => 'required|url:http,https|max:255',
            'remove_secret' => 'sometimes|boolean',
            'remove_webhook_secret' => 'sometimes|boolean',
        ]);
        if (! empty($data['secret'])) {
            abort_if($data['mode'] === 'test' && ! str_starts_with($data['secret'], 'sk_test_'), 422, 'Use a Stripe test key in test mode.');
            abort_if($data['mode'] === 'live' && ! str_starts_with($data['secret'], 'sk_live_'), 422, 'Use a Stripe live key in live mode.');
        }
        $settings = PlatformSetting::current();
        $settings->stripe_mode = $data['mode'];
        $settings->stripe_automatic_tax = $data['automatic_tax'];
        $url = parse_url($data['frontend_url']);
        $settings->frontend_url = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        if (! empty($data['secret'])) {
            $settings->stripe_secret = $data['secret'];
        } elseif ($data['remove_secret'] ?? false) {
            $settings->stripe_secret = null;
        }
        if (! empty($data['webhook_secret'])) {
            $settings->stripe_webhook_secret = $data['webhook_secret'];
        } elseif ($data['remove_webhook_secret'] ?? false) {
            $settings->stripe_webhook_secret = null;
        }
        $settings->save();
        Audit::record('stripe.settings_updated', $settings->id, [
            'mode' => $settings->stripe_mode,
            'automatic_tax' => $settings->stripe_automatic_tax,
            'frontend_url' => $settings->frontend_url,
            'secret_changed' => ! empty($data['secret']) || ($data['remove_secret'] ?? false),
            'webhook_secret_changed' => ! empty($data['webhook_secret']) || ($data['remove_webhook_secret'] ?? false),
        ]);

        return $this->stripeSettings();
    }

    public function testStripe(StripeGateway $stripe)
    {
        $account = $stripe->account();

        return ['connected' => true, 'account_id' => $account->id, 'charges_enabled' => (bool) ($account->charges_enabled ?? false)];
    }

    public function syncStripePrices(Request $request, StripeGateway $stripe)
    {
        $data = $request->validate(['currency' => 'required|string|size:3|alpha:ascii']);
        $currency = strtolower($data['currency']);
        $plans = Plan::where('slug', '!=', 'free')->where('is_active', true)->get();
        foreach ($plans as $plan) {
            if (! $plan->stripe_monthly_price_id || ! $plan->stripe_yearly_price_id) {
                $stripe->createPlanPrices($plan, $currency);
            }
        }
        Audit::record('stripe.prices_synchronized', null, ['currency' => $currency, 'plans' => $plans->pluck('slug')->all()]);

        return Plan::orderBy('monthly_price_cents')->get();
    }

    public function audit()
    {
        return DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name as actor', 'users.email as actor_email')->orderByDesc('audit_logs.id')->paginate(15);
    }
}
