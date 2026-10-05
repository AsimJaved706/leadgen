<?php

namespace App\Services;

use App\Models\Workspace;
use App\Support\WorkspaceMailer;
use RuntimeException;

class WorkspaceReplySync
{
    public function sync(Workspace $workspace): array
    {
        $setting = $workspace->emailSetting;
        if (! $setting?->is_active || blank($setting->password)) {
            throw new RuntimeException('Save and activate the email credentials before syncing replies.');
        }

        [$host, $port] = $this->imapServer($setting->host);
        WorkspaceMailer::assertSafeHost($host);
        $username = filter_var($setting->username, FILTER_VALIDATE_EMAIL) ? $setting->username : $setting->from_email;
        $connection = $this->connect($host, $port);
        $this->command($connection, 'LOGIN '.$this->quote($username).' '.$this->quote($setting->password));
        $this->command($connection, 'SELECT INBOX');

        $checked = $matched = 0;
        try {
            $recipients = $workspace->emailCampaigns()->with(['recipients' => fn ($query) => $query
                ->where('status', 'sent')->whereNull('replied_at')->whereNotNull('sent_at')
                ->where('sent_at', '>=', now()->subDays(45))->orderByDesc('sent_at')])
                ->get()->pluck('recipients')->flatten()->take(500);

            foreach ($recipients as $recipient) {
                $checked++;
                $since = $recipient->sent_at->copy()->subDay()->format('d-M-Y');
                $search = $this->command($connection, 'UID SEARCH FROM '.$this->quote($recipient->email).' SINCE '.$this->quote($since));
                preg_match('/^\* SEARCH(.*)$/mi', $search, $match);
                $messages = array_values(array_filter(preg_split('/\s+/', trim($match[1] ?? ''))));
                foreach (array_reverse($messages) as $uid) {
                    $fetch = $this->command($connection, 'UID FETCH '.(int) $uid.' (INTERNALDATE)');
                    preg_match('/INTERNALDATE "([^"]+)"/i', $fetch, $dateMatch);
                    $receivedAt = isset($dateMatch[1]) ? strtotime($dateMatch[1]) : false;
                    if ($receivedAt && $receivedAt > $recipient->sent_at->timestamp) {
                        $recipient->update(['replied_at' => date('Y-m-d H:i:s', $receivedAt)]);
                        $matched++;
                        break;
                    }
                }
            }
        } finally {
            try {
                $this->command($connection, 'LOGOUT', false);
            } finally {
                fclose($connection);
            }
        }

        return compact('checked', 'matched');
    }

    private function connect(string $host, int $port)
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $connection = @stream_socket_client("ssl://{$host}:{$port}", $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
        if (! $connection) {
            throw new RuntimeException('IMAP connection failed: '.$errorMessage);
        }
        stream_set_timeout($connection, 20);
        $greeting = fgets($connection);
        if (! is_string($greeting) || ! str_starts_with($greeting, '* OK')) {
            fclose($connection);
            throw new RuntimeException('The IMAP server did not accept the secure connection.');
        }

        return $connection;
    }

    private function command($connection, string $command, bool $requireOk = true): string
    {
        static $sequence = 0;
        $tag = 'LS'.str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT);
        fwrite($connection, $tag.' '.$command."\r\n");
        $response = '';
        while (($line = fgets($connection)) !== false) {
            $response .= $line;
            if (str_starts_with($line, $tag.' ')) {
                if ($requireOk && ! str_starts_with($line, $tag.' OK')) {
                    throw new RuntimeException('IMAP reply sync failed: '.trim(preg_replace('/^'.preg_quote($tag, '/').'\s+/i', '', $line)));
                }
                return $response;
            }
        }
        throw new RuntimeException('The IMAP server closed the connection unexpectedly.');
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, "\\\"").'"';
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
