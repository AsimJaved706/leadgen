<?php

namespace App\Http\Controllers;

use App\Models\LeadList;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    private function authorizeWorkspace(Request $r, Workspace $w, bool $write = false): void
    {
        $member = $w->members()->where('users.id', $r->user()->id)->first();
        abort_unless($member, 404);
        abort_if($w->suspended_at, 403, 'This workspace is suspended.');
        abort_if($w->plan->slug === 'free', 402, 'A paid subscription is required to access this workspace.');
        abort_if($write && $member->pivot->role === 'viewer', 403, 'Viewers cannot modify leads.');
    }

    public function leads(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace);
        $d = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'email_status' => 'nullable|in:all,with_email,without_email', 'sort' => 'nullable|in:name,created_at,average_rating', 'direction' => 'nullable|in:asc,desc', 'per_page' => 'nullable|integer|in:15,30,50']);

        return $this->filteredLeads($workspace, $d)->orderBy($d['sort'] ?? 'created_at', $d['direction'] ?? 'desc')->orderBy('id')->paginate($d['per_page'] ?? 15);
    }

    public function createLead(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace, true);
        $data = $r->validate(['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:100', 'website' => 'nullable|url:http,https|max:255', 'city' => 'nullable|string|max:100', 'country' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'address' => 'nullable|string|max:2000', 'place_id' => 'nullable|string|max:255', 'cid' => 'nullable|string|max:255', 'average_rating' => 'nullable|numeric|between:0,5', 'additional_details' => 'nullable|array']);

        return DB::transaction(function () use ($workspace, $data) {
            $w = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $data['website_domain'] = ! empty($data['website']) ? preg_replace('/^www\./', '', strtolower(parse_url($data['website'], PHP_URL_HOST))) : null;
            $data['normalized_phone'] = ! empty($data['phone']) ? preg_replace('/\D/', '', $data['phone']) : null;
            $data['name_address_hash'] = ! empty($data['address']) ? hash('sha256', mb_strtolower(trim($data['name']).'|'.trim($data['address']))) : null;
            foreach (['place_id', 'cid', 'website_domain', 'normalized_phone', 'name_address_hash'] as $field) {
                if (! empty($data[$field]) && $w->leads()->where($field, $data[$field])->exists()) {
                    throw ValidationException::withMessages(['name' => 'A matching lead already exists in this workspace.']);
                }
            }
            abort_if($w->leads()->count() >= ($w->plan->limits['leads'] ?? 0), 422, 'Your plan’s lead storage limit has been reached.');
            $lead = $w->leads()->create($data + ['collected_at' => now()]);
            Audit::record('lead.created', $lead->id, [], $w->id);

            return response()->json($lead, 201);
        });
    }

    public function showLead(Request $r, Workspace $workspace, int $lead)
    {
        $this->authorizeWorkspace($r, $workspace);

        return $workspace->leads()->findOrFail($lead);
    }

    public function deleteLead(Request $r, Workspace $workspace, int $lead)
    {
        $this->authorizeWorkspace($r, $workspace, true);
        $record = $workspace->leads()->findOrFail($lead);
        DB::transaction(function () use ($record, $workspace) {
            Audit::record('lead.deleted', $record->id, [], $workspace->id);
            $record->delete();
        });

        return response()->noContent();
    }

    public function deleteLeads(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace, true);
        $data = $r->validate([
            'mode' => 'required|in:selected,filtered,all', 'ids' => 'required_if:mode,selected|array|max:2000', 'ids.*' => 'integer',
            'q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'email_status' => 'nullable|in:all,with_email,without_email',
        ]);
        $query = $workspace->leads();
        if ($data['mode'] === 'selected') {
            $query->whereIn('id', $data['ids']);
        } elseif ($data['mode'] === 'filtered') {
            $query = $this->filteredLeads($workspace, $data);
        }
        $ids = $query->pluck('id');
        $deleted = $ids->count();
        DB::transaction(function () use ($workspace, $ids, $deleted, $data) {
            if ($deleted) {
                $workspace->leads()->whereIn('id', $ids)->delete();
            }
            Audit::record('leads.bulk_deleted', null, ['count' => $deleted, 'mode' => $data['mode']], $workspace->id);
        });

        return ['deleted' => $deleted];
    }

    private function filteredLeads(Workspace $workspace, array $data)
    {
        return $workspace->leads()
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('city', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(($data['email_status'] ?? 'all') === 'with_email', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->when(($data['email_status'] ?? 'all') === 'without_email', fn ($q) => $q->where(fn ($q) => $q->whereNull('email')->orWhere('email', '')));
    }

    public function lists(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace);

        return $workspace->lists()->withCount('leads')->latest()->paginate(30);
    }

    public function createList(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace, true);
        $data = $r->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:1000']);

        return DB::transaction(function () use ($workspace, $data) {
            $w = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_if($w->lists()->count() >= ($w->plan->limits['lists'] ?? 0), 422, 'Your plan’s list limit has been reached.');
            if ($w->lists()->where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'This list already exists.']);
            }
            $list = $w->lists()->create($data);
            Audit::record('list.created', $list->id, [], $w->id);

            return response()->json($list, 201);
        });
    }

    public function addLeadsToList(Request $r, Workspace $workspace, LeadList $list)
    {
        $this->authorizeWorkspace($r, $workspace, true);
        abort_unless($list->workspace_id === $workspace->id, 404);
        $data = $r->validate(['lead_ids' => 'required|array|min:1|max:2000', 'lead_ids.*' => 'required|integer|distinct']);
        $leadIds = $workspace->leads()->whereIn('id', $data['lead_ids'])->pluck('id');
        abort_if($leadIds->count() !== count($data['lead_ids']), 422, 'One or more selected leads do not belong to this workspace.');
        $before = $list->leads()->count();
        $list->leads()->syncWithoutDetaching($leadIds);
        $added = $list->leads()->count() - $before;
        Audit::record('list.leads_added', $list->id, ['added' => $added, 'selected' => $leadIds->count()], $workspace->id);

        return ['added' => $added, 'total' => $list->leads()->count()];
    }

    public function summary(Request $r, Workspace $workspace)
    {
        $this->authorizeWorkspace($r, $workspace);

        $days = (int) ($r->validate(['days' => 'sometimes|integer|in:7,30'])['days'] ?? 30);
        $start = now()->startOfDay()->subDays($days - 1);
        $counts = $workspace->leads()->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->pluck('total', 'day');
        $growth = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $growth[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }
        $categories = $workspace->leads()->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->get()
            ->groupBy(fn ($row) => filled($row->category) ? $row->category : 'Uncategorized')
            ->map(fn ($rows, $name) => ['name' => $name, 'total' => (int) $rows->sum('total')])
            ->sortByDesc('total')->values();

        return [
            'workspace' => $workspace->load('plan'),
            'leads' => $workspace->leads()->count(),
            'lists' => $workspace->lists()->count(),
            'members' => $workspace->members()->count(),
            'added' => array_sum(array_column($growth, 'count')),
            'enriched' => $workspace->leads()->whereNotNull('enriched_at')->count(),
            'growth' => $growth,
            'categories' => $categories,
            'recent_leads' => $workspace->leads()->latest()->orderByDesc('id')->limit(5)->get(),
            'recent_lists' => $workspace->lists()->withCount('leads')->latest()->limit(3)->get(),
        ];
    }
}
