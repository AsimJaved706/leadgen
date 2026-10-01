<?php

namespace App\Http\Controllers;

use App\Models\LeadList;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\ExtensionAccess;
use App\Support\GoogleMapsLeadNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'leads.*' => 'required|array',
        ]);
        $list = $workspace->lists()->findOrFail($data['list_id']);

        return DB::transaction(function () use ($workspace, $list, $data) {
            $locked = Workspace::whereKey($workspace->id)->lockForUpdate()->with('plan')->firstOrFail();
            $limit = (int) ($locked->plan->limits['leads'] ?? 0);
            $created = $updated = $failed = 0;
            $errors = [];
            $count = $locked->leads()->count();
            foreach ($data['leads'] as $index => $raw) {
                try {
                $leadData = GoogleMapsLeadNormalizer::normalize($raw);
                $identifiers = array_filter(array_intersect_key($leadData, array_flip(['place_id', 'cid', 'website_domain', 'normalized_phone', 'name_address_hash', 'email'])));
                $match = $identifiers ? $locked->leads()->where(function ($query) use ($identifiers) {
                    foreach ($identifiers as $field => $value) {
                        $query->orWhere($field, $value);
                    }
                })->first() : null;
                if ($match) {
                    $lead = $match;
                    $lead->fill($leadData)->save();
                    $updated++;
                } else {
                    if ($count >= $limit) {
                        throw new \RuntimeException('The workspace lead storage limit has been reached.');
                    }
                    $lead = $locked->leads()->create($leadData + ['collected_at' => $leadData['collected_at'] ?? now()]);
                    $created++;
                    $count++;
                }
                $list->leads()->syncWithoutDetaching([$lead->id]);
                } catch (\Throwable $exception) {
                    $failed++;
                    $errors[] = ['row' => $index + 1, 'name' => mb_substr((string) ($raw['name'] ?? $raw['Name'] ?? 'Unknown lead'), 0, 100), 'message' => mb_substr($exception->getMessage(), 0, 300)];
                }
            }
            Audit::record('extension.leads_saved', $list->id, ['created' => $created, 'updated' => $updated, 'failed' => $failed, 'received' => count($data['leads'])], $workspace->id);

            return ['created' => $created, 'updated' => $updated, 'existing' => $updated, 'failed' => $failed, 'saved' => $created + $updated, 'errors' => array_slice($errors, 0, 20), 'list' => $list->name];
        });
    }
}
