<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Audit;
use App\Support\GoogleMapsLeadNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadImportController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace)
    {
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($workspace->suspended_at, 403, 'This workspace is suspended.');
        abort_if($workspace->plan->slug === 'free', 402, 'A paid subscription is required to access this workspace.');
        abort_if($member->pivot->role === 'viewer', 403, 'Viewers cannot import leads.');
        $payload = $request->validate(['name' => 'required|string|max:255', 'rows' => 'required|array|min:1|max:2000', 'rows.*' => 'required|array']);

        return DB::transaction(function () use ($workspace, $payload) {
            $workspace = Workspace::whereKey($workspace->id)->lockForUpdate()->with('plan')->firstOrFail();
            $importId = DB::table('imports')->insertGetId([
                'workspace_id' => $workspace->id, 'request_uuid' => (string) Str::uuid(), 'name' => $payload['name'],
                'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $created = $updated = $failed = 0;
            $errors = [];
            $limit = (int) ($workspace->plan->limits['leads'] ?? 0);
            $count = $workspace->leads()->count();

            foreach ($payload['rows'] as $index => $row) {
                try {
                    $data = GoogleMapsLeadNormalizer::normalize($row);
                    $lead = null;
                    foreach (['cid', 'place_id', 'google_maps_url', 'normalized_phone', 'name_address_hash'] as $field) {
                        if (! empty($data[$field])) {
                            $lead = $workspace->leads()->where($field, $data[$field])->first();
                            if ($lead) {
                                break;
                            }
                        }
                    }
                    if ($lead) {
                        $lead->fill($data)->save();
                        $updated++;
                    } else {
                        if ($count >= $limit) {
                            throw new \RuntimeException('Workspace lead storage limit reached.');
                        }
                        $workspace->leads()->create($data);
                        $created++;
                        $count++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $message = mb_substr($e->getMessage(), 0, 500);
                    $errors[] = ['row' => $index + 2, 'message' => $message];
                    DB::table('import_failures')->insert(['import_id' => $importId, 'row_number' => $index + 2, 'errors' => json_encode([$message]), 'raw_data' => json_encode($row), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $result = ['created' => $created, 'updated' => $updated, 'failed' => $failed, 'errors' => array_slice($errors, 0, 20)];
            DB::table('imports')->where('id', $importId)->update(['status' => $failed && ! ($created + $updated) ? 'failed' : 'completed', 'created_count' => $created, 'updated_count' => $updated, 'failed_count' => $failed, 'result' => json_encode($result), 'updated_at' => now()]);
            Audit::record('leads.imported', $importId, $result, $workspace->id);

            return response()->json($result, 201);
        });
    }
}
