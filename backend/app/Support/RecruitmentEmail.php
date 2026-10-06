<?php

namespace App\Support;

use Illuminate\Support\Str;

final class RecruitmentEmail
{
    private const RECRUITMENT_LOCALS = ['career', 'careers', 'job', 'jobs', 'recruit', 'recruiter', 'recruiting', 'recruitment', 'talent', 'talentacquisition', 'hr', 'hiring', 'people', 'peopleops'];
    private const BLOCKED_LOCALS = ['support', 'help', 'helpdesk', 'info', 'contact', 'admin', 'office', 'sales', 'billing', 'accounts', 'service', 'customerservice', 'success', 'privacy', 'legal', 'abuse', 'security', 'press', 'media', 'marketing', 'webmaster', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'example'];

    public static function normalize(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function local(?string $email): string
    {
        return Str::before(self::normalize($email) ?? '', '@');
    }

    public static function isBlocked(?string $email): bool
    {
        $local = preg_replace('/[^a-z-]/', '', self::local($email));
        return ! $local || in_array($local, self::BLOCKED_LOCALS, true)
            || Str::startsWith($local, ['support', 'helpdesk', 'customer', 'billing', 'privacy', 'legal', 'noreply', 'no-reply']);
    }

    public static function isRecruitmentRole(?string $email): bool
    {
        $local = preg_replace('/[^a-z]/', '', self::local($email));
        return in_array($local, self::RECRUITMENT_LOCALS, true)
            || Str::startsWith($local, ['career', 'recruit', 'talent', 'hiring']);
    }

    public static function acceptPublished(?string $email): ?string
    {
        $email = self::normalize($email);
        return $email && ! self::isBlocked($email) ? $email : null;
    }

    public static function acceptWebsite(?string $email): ?string
    {
        $email = self::normalize($email);
        return $email && ! self::isBlocked($email) && self::isRecruitmentRole($email) ? $email : null;
    }

    public static function eligibleStatuses(): array
    {
        return ['published', 'published_job', 'recruitment_page', 'manual_verified'];
    }

    public static function blockedPrefixes(): array
    {
        return array_values(array_unique([...self::BLOCKED_LOCALS, 'customer']));
    }

    public static function isCampaignEligible(?string $email, ?string $status): bool
    {
        return in_array($status, self::eligibleStatuses(), true)
            && self::normalize($email) !== null && ! self::isBlocked($email);
    }
}
