<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareEmailCampaign;
use App\Models\CampaignAudienceGroup;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\WorkspaceMailer;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmailMarketingController extends Controller
{
    private function member(Request $request, Workspace $workspace)
    {
        $member = $workspace->members()->where('users.id', $request->user()->id)->first();
        abort_unless($member, 404);
        abort_if($workspace->suspended_at, 403, 'This workspace is suspended.');
        abort_if($workspace->plan->slug === 'free', 402, 'A paid subscription is required to access this workspace.');

        return $member;
    }

    private function canWrite(Request $request, Workspace $workspace): void
    {
        abort_unless(in_array($this->member($request, $workspace)->pivot->role, ['owner', 'admin'], true), 403, 'Only workspace owners and admins can manage email marketing.');
    }

    private function canConfigure(Request $request, Workspace $workspace): void
    {
        abort_unless(in_array($this->member($request, $workspace)->pivot->role, ['owner', 'admin'], true), 403, 'Only workspace owners and admins can configure SMTP.');
    }

    public function settings(Request $request, Workspace $workspace)
    {
        $this->canConfigure($request, $workspace);
        $setting = $workspace->emailSetting;

        return $setting ? array_merge($setting->makeHidden(['password'])->toArray(), ['has_password' => filled($setting->password)]) : null;
    }

    public function saveSettings(Request $request, Workspace $workspace)
    {
        $this->canConfigure($request, $workspace);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
            'port' => 'required|integer|between:1,65535',
            'encryption' => 'required|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:2000',
            'from_email' => 'required|email:rfc|max:255',
            'from_name' => 'required|string|max:120',
            'reply_to_email' => 'nullable|email:rfc|max:255',
            'is_active' => 'required|boolean',
        ]);
        WorkspaceMailer::assertSafeHost($data['host']);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } elseif (in_array(strtolower($data['host']), ['smtp.gmail.com', 'smtp.googlemail.com'], true)) {
            // Google displays App Passwords in four groups; SMTP expects the 16 characters.
            $data['password'] = preg_replace('/\s+/', '', $data['password']);
        }
        $setting = $workspace->emailSetting()->updateOrCreate([], $data);
        Audit::record('email.settings_updated', $setting->id, ['host' => $setting->host, 'active' => $setting->is_active], $workspace->id);

        return array_merge($setting->makeHidden(['password'])->toArray(), ['has_password' => filled($setting->password)]);
    }

    public function testSettings(Request $request, Workspace $workspace)
    {
        $this->canConfigure($request, $workspace);
        $setting = $workspace->emailSetting;
        abort_unless($setting?->is_active, 422, 'Save and activate SMTP settings first.');
        try {
            WorkspaceMailer::build($setting)->html('<h2>Leadspace SMTP test</h2><p>Your workspace email connection is working.</p>', function (Message $message) use ($request, $setting) {
                $message->to($request->user()->email, $request->user()->name)->from($setting->from_email, $setting->from_name)->subject('Leadspace SMTP connection test');
            });
        } catch (\Throwable $exception) {
            abort(422, 'SMTP test failed: '.mb_substr($exception->getMessage(), 0, 500));
        }
        $setting->update(['verified_at' => now()]);
        Audit::record('email.smtp_tested', $setting->id, [], $workspace->id);

        return ['message' => 'Test email sent to '.$request->user()->email.'.'];
    }

    public function templates(Request $request, Workspace $workspace)
    {
        $this->member($request, $workspace);

        return $workspace->emailTemplates()->latest()->paginate(20);
    }

    public function saveTemplate(Request $request, Workspace $workspace, ?EmailTemplate $template = null)
    {
        $this->canWrite($request, $workspace);
        if ($template) {
            abort_unless($template->workspace_id === $workspace->id, 404);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('email_templates')->where('workspace_id', $workspace->id)->ignore($template?->id)],
            'subject' => 'required|string|max:255',
            'html_body' => 'required|string|max:200000',
            'text_body' => 'nullable|string|max:100000',
            'is_active' => 'sometimes|boolean',
        ]);
        $data['html_body'] = WorkspaceMailer::sanitizeHtml($data['html_body']);
        $template = $template ? tap($template)->update($data) : $workspace->emailTemplates()->create($data);
        Audit::record('email.template_saved', $template->id, ['name' => $template->name], $workspace->id);

        return response()->json($template, $template->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteTemplate(Request $request, Workspace $workspace, EmailTemplate $template)
    {
        $this->canWrite($request, $workspace);
        abort_unless($template->workspace_id === $workspace->id, 404);
        abort_if($template->campaigns()->exists(), 422, 'Templates used by campaigns cannot be deleted. Disable or duplicate the template instead.');
        $template->delete();

        return response()->noContent();
    }

    public function campaigns(Request $request, Workspace $workspace)
    {
        $this->member($request, $workspace);

        return $workspace->emailCampaigns()->with(['template:id,name,subject', 'leadList:id,name', 'audienceGroup:id,name'])
            ->withCount([
                'recipients as opened_count' => fn ($query) => $query->whereNotNull('opened_at'),
                'recipients as replied_count' => fn ($query) => $query->whereNotNull('replied_at'),
            ])->latest()->paginate(20);
    }

    public function audienceGroups(Request $request, Workspace $workspace): array
    {
        $this->member($request, $workspace);
        $groups = $workspace->campaignAudienceGroups()->latest()->get()->map(function ($group) {
            $group->setAttribute('jobs_count', $group->jobsQuery()->count());
            return $group;
        });
        $countries = CampaignAudienceGroup::eligibleJobs($workspace)->whereNotNull('country')->where('country', '!=', '')
            ->select('country')->selectRaw('COUNT(*) as total')->selectRaw('COUNT(*) as with_email')
            ->groupBy('country')->orderBy('country')->get();

        return ['groups' => $groups, 'countries' => $countries];
    }

    public function saveAudienceGroup(Request $request, Workspace $workspace)
    {
        $this->canWrite($request, $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('campaign_audience_groups')->where('workspace_id', $workspace->id)],
            'countries' => 'required|array|min:1|max:50', 'countries.*' => 'required|string|max:100|distinct',
            'require_email' => 'required|boolean',
        ]);
        $data['require_email'] = true;
        $available = $workspace->jobs()->whereIn('country', $data['countries'])->distinct()->pluck('country')->all();
        abort_if(count($available) !== count($data['countries']), 422, 'One or more selected countries are not available in this workspace.');
        $group = $workspace->campaignAudienceGroups()->create($data);
        $group->setAttribute('jobs_count', $group->jobsQuery()->count());
        Audit::record('email.audience_group_created', $group->id, ['countries' => $group->countries], $workspace->id);

        return response()->json($group, 201);
    }

    public function deleteAudienceGroup(Request $request, Workspace $workspace, CampaignAudienceGroup $group)
    {
        $this->canWrite($request, $workspace);
        abort_unless($group->workspace_id === $workspace->id, 404);
        abort_if($group->campaigns()->whereIn('status', ['scheduled', 'preparing', 'sending'])->exists(), 422, 'This group is being used by an active campaign.');
        $group->delete();
        return response()->noContent();
    }

    public function campaignRecipients(Request $request, Workspace $workspace, EmailCampaign $campaign)
    {
        $this->member($request, $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        return $campaign->recipients()->select(['id', 'email_campaign_id', 'name', 'email', 'status', 'failure_reason', 'sent_at', 'opened_at', 'open_count', 'replied_at'])
            ->orderBy('id')->paginate(50);
    }

    public function createCampaign(Request $request, Workspace $workspace)
    {
        $this->canWrite($request, $workspace);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email_template_id' => ['required', Rule::exists('email_templates', 'id')->where('workspace_id', $workspace->id)->where('is_active', true)],
            'audience_type' => 'required|in:all,list,group',
            'lead_list_id' => ['nullable', 'required_if:audience_type,list', Rule::exists('lead_lists', 'id')->where('workspace_id', $workspace->id)],
            'campaign_audience_group_id' => ['nullable', 'required_if:audience_type,group', Rule::exists('campaign_audience_groups', 'id')->where('workspace_id', $workspace->id)],
            'send_mode' => 'required|in:draft,now,schedule',
            'scheduled_at' => 'nullable|required_if:send_mode,schedule|date|after:now',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,doc,docx,txt,rtf',
        ]);
        if ($data['send_mode'] !== 'draft') {
            abort_unless($workspace->emailSetting?->is_active, 422, 'Configure and activate SMTP before sending or scheduling a campaign.');
        }
        $status = match ($data['send_mode']) {
            'draft' => 'draft', 'now' => 'scheduled', default => 'scheduled'
        };
        $scheduledAt = $data['send_mode'] === 'now' ? now() : ($data['scheduled_at'] ?? null);
        $attachment = $request->file('attachment');
        $attachmentPath = $attachment?->store('email-attachments/'.$workspace->id, 'local');
        try {
            $campaign = $workspace->emailCampaigns()->create([
                'name' => $data['name'], 'email_template_id' => $data['email_template_id'],
                'audience_type' => $data['audience_type'], 'lead_list_id' => $data['lead_list_id'] ?? null,
                'campaign_audience_group_id' => $data['campaign_audience_group_id'] ?? null,
                'created_by' => $request->user()->id, 'status' => $status, 'scheduled_at' => $scheduledAt,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachment ? basename($attachment->getClientOriginalName()) : null,
                'attachment_mime' => $attachment?->getMimeType(),
                'attachment_size' => $attachment?->getSize(),
            ]);
        } catch (\Throwable $exception) {
            if ($attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }
            throw $exception;
        }
        Audit::record('email.campaign_created', $campaign->id, ['status' => $status], $workspace->id);
        if ($data['send_mode'] === 'now') {
            PrepareEmailCampaign::dispatch($campaign->id)->afterCommit();
        }

        return response()->json($campaign->load(['template:id,name,subject', 'leadList:id,name', 'audienceGroup:id,name']), 201);
    }

    public function cancelCampaign(Request $request, Workspace $workspace, EmailCampaign $campaign)
    {
        $this->canWrite($request, $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        abort_unless(in_array($campaign->status, ['draft', 'scheduled', 'preparing', 'sending'], true), 422, 'This campaign can no longer be stopped.');
        $wasSending = in_array($campaign->status, ['preparing', 'sending'], true);
        $campaign->update(['status' => 'cancelled']);
        Audit::record($wasSending ? 'email.campaign_stopped' : 'email.campaign_cancelled', $campaign->id, [], $workspace->id);

        return $campaign;
    }

    public function sendCampaign(Request $request, Workspace $workspace, EmailCampaign $campaign)
    {
        $this->canWrite($request, $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        abort_unless(in_array($campaign->status, ['draft', 'cancelled'], true), 422, 'Only draft or cancelled campaigns can be started.');
        abort_unless($workspace->emailSetting?->is_active, 422, 'Configure and activate SMTP before sending a campaign.');
        $retried = $campaign->status === 'cancelled';
        $campaign->update(['status' => 'scheduled', 'scheduled_at' => now(), 'started_at' => null, 'completed_at' => null, 'failure_reason' => null]);
        Audit::record($retried ? 'email.campaign_retried' : 'email.campaign_started', $campaign->id, [], $workspace->id);
        PrepareEmailCampaign::dispatch($campaign->id)->afterCommit();

        return $campaign;
    }
}
