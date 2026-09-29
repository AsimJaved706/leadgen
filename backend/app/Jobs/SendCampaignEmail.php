<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Support\WorkspaceMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $recipientId) {}

    public function handle(): void
    {
        $recipient = DB::transaction(function () {
            $recipient = EmailCampaignRecipient::whereKey($this->recipientId)->lockForUpdate()->firstOrFail();
            if (in_array($recipient->status, ['sent', 'skipped'], true)) {
                return null;
            }
            if (! in_array($recipient->campaign->status, ['sending', 'preparing'], true)) {
                $recipient->update(['status' => 'skipped', 'failure_reason' => 'Campaign is no longer sending.']);
                $this->refreshCampaign($recipient->campaign);

                return null;
            }
            $recipient->update(['status' => 'processing', 'failure_reason' => null]);

            return $recipient->load(['campaign.template', 'campaign.workspace.emailSetting', 'lead']);
        });
        if (! $recipient) {
            return;
        }
        try {
            $campaign = $recipient->campaign;
            $setting = $campaign->workspace->emailSetting;
            if (! $setting?->is_active) {
                throw new \RuntimeException('SMTP is not active.');
            }
            $lead = $recipient->lead?->toArray() ?? ['name' => $recipient->name, 'email' => $recipient->email];
            $subject = html_entity_decode(strip_tags(WorkspaceMailer::render($campaign->template->subject, $lead)), ENT_QUOTES | ENT_HTML5);
            $text = $campaign->template->text_body ? html_entity_decode(strip_tags(WorkspaceMailer::render($campaign->template->text_body, $lead)), ENT_QUOTES | ENT_HTML5) : null;
            $unsubscribe = URL::signedRoute('email.unsubscribe', ['recipient' => $recipient->id]);
            $openPixel = URL::signedRoute('email.open', ['recipient' => $recipient->id]);
            $html = WorkspaceMailer::render($campaign->template->html_body, $lead).'<hr style="border:0;border-top:1px solid #e5e7eb;margin:32px 0 18px"><p style="font-size:12px;color:#64748b">You received this email because your business contact was included in a Leadspace campaign. <a href="'.e($unsubscribe).'">Unsubscribe</a>.</p><img src="'.e($openPixel).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">';
            WorkspaceMailer::build($setting)->html($html, function (Message $message) use ($campaign, $recipient, $setting, $subject, $unsubscribe, $text) {
                $message->to($recipient->email, $recipient->name)->from($setting->from_email, $setting->from_name)->subject($subject);
                if ($setting->reply_to_email) {
                    $message->replyTo($setting->reply_to_email);
                }
                if ($text) {
                    $message->getSymfonyMessage()->text($text."\n\nUnsubscribe: ".$unsubscribe);
                }
                if ($campaign->attachment_path) {
                    $disk = Storage::disk('local');
                    if (! $disk->exists($campaign->attachment_path)) {
                        throw new \RuntimeException('Campaign attachment is missing.');
                    }
                    $message->attach($disk->path($campaign->attachment_path), [
                        'as' => $campaign->attachment_name,
                        'mime' => $campaign->attachment_mime,
                    ]);
                }
                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
            });
            DB::transaction(function () use ($recipient) {
                $fresh = EmailCampaignRecipient::whereKey($recipient->id)->lockForUpdate()->firstOrFail();
                $fresh->update(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null]);
                $this->refreshCampaign($fresh->campaign);
            });
        } catch (Throwable $exception) {
            EmailCampaignRecipient::whereKey($this->recipientId)->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception) {
            $recipient = EmailCampaignRecipient::whereKey($this->recipientId)->lockForUpdate()->first();
            if (! $recipient || $recipient->status === 'sent') {
                return;
            }
            $recipient->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000)]);
            $this->refreshCampaign($recipient->campaign);
        });
    }

    private function refreshCampaign(EmailCampaign $campaign): void
    {
        $campaign = EmailCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        $counts = $campaign->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $campaign->sent_count = (int) ($counts['sent'] ?? 0);
        $campaign->failed_count = (int) ($counts['failed'] ?? 0);
        $campaign->skipped_count = (int) ($counts['skipped'] ?? 0);
        if (((int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0)) === 0) {
            $campaign->status = $campaign->failed_count && ! $campaign->sent_count ? 'failed' : 'completed';
            $campaign->completed_at = now();
        }
        $campaign->save();
    }
}
