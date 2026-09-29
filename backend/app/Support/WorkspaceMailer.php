<?php

namespace App\Support;

use App\Models\EmailSetting;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceMailer
{
    public static function build(EmailSetting $setting)
    {
        self::assertSafeHost($setting->host);

        $scheme = match ($setting->encryption) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => null,
        };

        return app(MailManager::class)->build([
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => $setting->host,
            'port' => $setting->port,
            'username' => $setting->username,
            'password' => $setting->password,
            'timeout' => 15,
        ]);
    }

    public static function assertSafeHost(string $host): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (! $ips) {
            throw new RuntimeException('The SMTP hostname could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private or reserved SMTP hosts are not allowed.');
            }
        }
    }

    public static function render(string $content, array $lead): string
    {
        return preg_replace_callback('/\{\{\s*lead\.(name|email|phone|website|city|country|category)\s*\}\}/', function ($match) use ($lead) {
            return e((string) ($lead[$match[1]] ?? ''));
        }, $content);
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|base)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|base)[^>]*/?>#is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html);

        return Str::limit($html, 200000, '');
    }
}
