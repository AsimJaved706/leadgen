<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailUnsubscribe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PrepareEmailCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $recipientIds = DB::transaction(function () {
            $campaign = EmailCampaign::whereKey($this->campaignId)->lockForUpdate()->firstOrFail();
            if ($campaign->status !== 'scheduled' || ($campaign->scheduled_at && $campaign->scheduled_at->isFuture())) {
                return [];
            }
            if (! $campaign->workspace->emailSetting?->is_active) {
                $campaign->update(['status' => 'failed', 'failure_reason' => 'SMTP is not active.']);

                return [];
            }
            $campaign->update(['status' => 'preparing', 'started_at' => now(), 'failure_reason' => null]);
            $query = match ($campaign->audience_type) {
                'list' => $campaign->leadList?->leads(),
                'group' => $campaign->audienceGroup?->jobsQuery(),
                default => $campaign->workspace->leads(),
            };
            if (! $query) {
                $campaign->update(['status' => 'failed', 'failure_reason' => 'The selected audience no longer exists.']);

                return [];
            }
            $blocked = EmailUnsubscribe::where('workspace_id', $campaign->workspace_id)->pluck('email')->flip();
            $seen = [];
            $ids = [];
            $isJobs = $campaign->audience_type === 'group';
            $emailColumn = $isJobs ? 'contact_email' : 'email';
            $table = $isJobs ? 'workspace_jobs' : 'leads';
            $query->whereNotNull($emailColumn)->where($emailColumn, '!=', '')->orderBy($table.'.id')->chunkById(500, function ($records) use ($campaign, $blocked, $isJobs, $emailColumn, &$seen, &$ids) {
                foreach ($records as $record) {
                    $email = strtolower(trim($record->{$emailColumn}));
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email]) || $blocked->has($email)) {
                        continue;
                    }
                    $seen[$email] = true;
                    $ids[] = EmailCampaignRecipient::create(['email_campaign_id' => $campaign->id,
                        'lead_id' => $isJobs ? null : $record->id, 'job_id' => $isJobs ? $record->id : null,
                        'email' => $email, 'name' => $isJobs ? ($record->company_name ?: $record->title) : $record->name, 'status' => 'pending'])->id;
                }
            }, $table.'.id', 'id');
            $period = now()->format('Y-m');
            DB::table('usage_records')->insertOrIgnore(['workspace_id' => $campaign->workspace_id, 'metric' => 'emails_queued', 'period' => $period, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $usage = DB::table('usage_records')->where(['workspace_id' => $campaign->workspace_id, 'metric' => 'emails_queued', 'period' => $period])->lockForUpdate()->first();
            $limit = (int) ($campaign->workspace->plan->limits['monthly_emails'] ?? 0);
            if ($limit < 1 || ((int) $usage->quantity + count($ids)) > $limit) {
                EmailCampaignRecipient::whereIn('id', $ids)->delete();
                $campaign->update(['status' => 'failed', 'failure_reason' => 'This campaign exceeds the workspace monthly email limit.']);

                return [];
            }
            DB::table('usage_records')->where('id', $usage->id)->update(['quantity' => DB::raw('quantity + '.count($ids)), 'updated_at' => now()]);
            $campaign->update(['recipient_count' => count($ids), 'status' => count($ids) ? 'sending' : 'completed', 'completed_at' => count($ids) ? null : now()]);

            return $ids;
        });
        foreach ($recipientIds as $id) {
            SendCampaignEmail::dispatch($id);
        }
    }
}
