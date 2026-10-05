<?php

namespace App\Services;

use App\Models\Workspace;
use App\Support\WorkspaceMailer;
use RuntimeException;

class WorkspaceReplySync
{
    public function sync(Workspace $workspace): array
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('IMAP is not available on this server.');
        }

        $setting = $workspace->emailSetting;
        if (! $setting?->is_active || blank($setting->password)) {
            throw new RuntimeException('Save and activate the email credentials before syncing replies.');
        }

        [$host, $port] = $this->imapServer($setting->host);
        WorkspaceMailer::assertSafeHost($host);
        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $host, $port);
        $username = filter_var($setting->username, FILTER_VALIDATE_EMAIL) ? $setting->username : $setting->from_email;
        $connection = @imap_open($mailbox, $username, $setting->password, OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if (! $connection) {
            $message = collect(imap_errors() ?: [])->last() ?: 'The inbox rejected the saved credentials.';
            throw new RuntimeException('IMAP reply sync failed: '.$message);
        }

        $checked = $matched = 0;
        try {
            $recipients = $workspace->emailCampaigns()->with(['recipients' => fn ($query) => $query
                ->where('status', 'sent')->whereNull('replied_at')->whereNotNull('sent_at')
                ->where('sent_at', '>=', now()->subDays(45))->orderByDesc('sent_at')])
                ->get()->pluck('recipients')->flatten()->take(500);

            foreach ($recipients as $recipient) {
                $checked++;
                $since = $recipient->sent_at->copy()->subDay()->format('d-M-Y');
                $messages = @imap_search($connection, 'FROM "'.$recipient->email.'" SINCE "'.$since.'"', SE_UID) ?: [];
                foreach (array_reverse($messages) as $uid) {
                    $overview = @imap_fetch_overview($connection, (string) $uid, FT_UID)[0] ?? null;
                    $receivedAt = $overview?->date ? strtotime($overview->date) : false;
                    if ($receivedAt && $receivedAt > $recipient->sent_at->timestamp) {
                        $recipient->update(['replied_at' => date('Y-m-d H:i:s', $receivedAt)]);
                        $matched++;
                        break;
                    }
                }
            }
        } finally {
            imap_close($connection);
        }

        return compact('checked', 'matched');
    }

    private function imapServer(string $smtpHost): array
    {
        return match (strtolower($smtpHost)) {
            'smtp.gmail.com', 'smtp.googlemail.com' => ['imap.gmail.com', 993],
            'smtp.office365.com', 'smtp-mail.outlook.com' => ['outlook.office365.com', 993],
            'smtp.mail.yahoo.com' => ['imap.mail.yahoo.com', 993],
            default => ['imap.'.preg_replace('/^smtp\./i', '', $smtpHost), 993],
        };
    }
}
