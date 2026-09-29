<?php

namespace App\Http\Controllers;

use App\Models\LeadList;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\ExtensionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExtensionController extends Controller
{
    public function token(Request $request)
    {
        $request->validate(['extension_id' => ['required', 'string', 'regex:/^[a-p]{32}$/']]);
        abort_if($request->bearerToken(), 403, 'Reconnect through the Leadspace dashboard.');
        $expiresAt = now()->addMinutes(15);
        $token = $request->user()->createToken('Leadspace Chrome Extension', ['extension:read', 'extension:write'], $expiresAt);

        Audit::record('extension.connected', null, ['extension_id' => $request->string('extension_id')->toString(), 'expires_at' => $expiresAt->toISOString()]);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt->toISOString()];
    }

    public function context(Request $request)
    {
        abort_unless($request->user()->tokenCan('extension:read'), 403);
        $workspaces = $request->user()->workspaces()->with('plan', 'currentSubscription')->get()->map(function (Workspace $workspace) {
            $access = ExtensionAccess::status($workspace);
            $leadCount = $workspace->leads()->count();

            return [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspace->pivot->role,
                'plan' => ['name' => $workspace->plan->name, 'slug' => $workspace->plan->slug],
                'subscription' => $access['subscription'],
                'access' => ['allowed' => $access['allowed'], 'reason' => $access['reason']],
                'usage' => ['leads' => $leadCount, 'limit' => (int) ($workspace->plan->limits['leads'] ?? 0)],
                'lists' => $workspace->lists()->latest()->get(['id', 'name']),
            ];
        });

        return ['user' => $request->user()->only(['id', 'name', 'email']), 'workspaces' => $workspaces, 'checked_at' => now()->toISOString()];
    }

    public function storeLeads(Request $request, Workspace $workspace)
    {
        abort_unless($request->user()->tokenCan('extension:write'), 403);
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($member->pivot->role === 'viewer', 403, 'Viewers cannot save leads.');
        $access = ExtensionAccess::status($workspace);
        abort_unless($access['allowed'], 402, $access['reason']);
        $data = $request->validate([
            'list_id' => 'required|integer',
            'leads' => 'required|array|min:1|max:500',
            'leads.*.name' => 'required|string|max:255',
            'leads.*.email' => 'nullable|email|max:255',
            'leads.*.phone' => 'nullable|string|max:100',
            'leads.*.website' => 'nullable|url:http,https|max:255',
            'leads.*.address' => 'nullable|string|max:2000',
            'leads.*.city' => 'nullable|string|max:100',
            'leads.*.country' => 'nullable|string|max:100',
            'leads.*.category' => 'nullable|string|max:255',
            'leads.*.place_id' => 'nullable|string|max:255',
            'leads.*.cid' => 'nullable|string|max:255',
            'leads.*.google_maps_url' => 'nullable|url:http,https|max:2000',
            'leads.*.average_rating' => 'nullable|numeric|between:0,5',
            'leads.*.review_count' => 'nullable|integer|min:0',
            'leads.*.latitude' => 'nullable|numeric|between:-90,90',
            'leads.*.longitude' => 'nullable|numeric|between:-180,180',
            'leads.*.additional_details' => 'nullable|array',
        ]);
        $list = $workspace->lists()->findOrFail($data['list_id']);

        return DB::transaction(function () use ($workspace, $list, $data) {
            $locked = Workspace::whereKey($workspace->id)->lockForUpdate()->with('plan')->firstOrFail();
            $limit = (int) ($locked->plan->limits['leads'] ?? 0);
            $created = $existing = 0;
            foreach ($data['leads'] as $raw) {
                $leadData = array_filter($raw, fn ($value) => $value !== null && $value !== '');
                $leadData['website_domain'] = ! empty($leadData['website']) ? preg_replace('/^www\./', '', strtolower(parse_url($leadData['website'], PHP_URL_HOST))) : null;
                $leadData['normalized_phone'] = ! empty($leadData['phone']) ? preg_replace('/\D/', '', $leadData['phone']) : null;
                $leadData['name_address_hash'] = ! empty($leadData['address']) ? hash('sha256', mb_strtolower(trim($leadData['name']).'|'.trim($leadData['address']))) : null;
                $identifiers = array_filter(array_intersect_key($leadData, array_flip(['place_id', 'cid', 'website_domain', 'normalized_phone', 'name_address_hash', 'email'])));
                $match = $identifiers ? $locked->leads()->where(function ($query) use ($identifiers) {
                    foreach ($identifiers as $field => $value) {
                        $query->orWhere($field, $value);
                    }
                })->first() : null;
                if ($match) {
                    $lead = $match;
                    $existing++;
                } else {
                    if ($locked->leads()->count() >= $limit) {
                        throw ValidationException::withMessages(['leads' => 'The workspace lead storage limit has been reached.']);
                    }
                    $lead = $locked->leads()->create($leadData + ['collected_at' => now()]);
                    $created++;
                }
                $list->leads()->syncWithoutDetaching([$lead->id]);
            }
            Audit::record('extension.leads_saved', $list->id, ['created' => $created, 'existing' => $existing, 'received' => count($data['leads'])], $workspace->id);

            return ['created' => $created, 'existing' => $existing, 'saved' => $created + $existing, 'list' => $list->name];
        });
    }
}
