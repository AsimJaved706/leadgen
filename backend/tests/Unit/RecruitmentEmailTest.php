<?php

namespace Tests\Unit;

use App\Support\RecruitmentEmail;
use PHPUnit\Framework\TestCase;

class RecruitmentEmailTest extends TestCase
{
    public function test_generic_service_addresses_are_blocked(): void
    {
        foreach (['support@company.com', 'info@company.com', 'helpdesk@company.com', 'billing@company.com', 'noreply@company.com'] as $email) {
            $this->assertTrue(RecruitmentEmail::isBlocked($email));
            $this->assertNull(RecruitmentEmail::acceptPublished($email));
            $this->assertNull(RecruitmentEmail::acceptWebsite($email));
        }
    }

    public function test_recruitment_role_addresses_are_eligible_from_company_websites(): void
    {
        foreach (['careers@company.com', 'jobs@company.com', 'recruiting@company.com', 'talent.acquisition@company.com', 'hr@company.com'] as $email) {
            $this->assertSame($email, RecruitmentEmail::acceptWebsite($email));
        }
    }

    public function test_named_address_requires_stronger_evidence_than_a_company_page(): void
    {
        $this->assertNull(RecruitmentEmail::acceptWebsite('jsmith@company.com'));
        $this->assertSame('jsmith@company.com', RecruitmentEmail::acceptPublished('JSmith@Company.com'));
    }

    public function test_legacy_status_cannot_make_a_support_address_campaign_eligible(): void
    {
        $this->assertFalse(RecruitmentEmail::isCampaignEligible('support@company.com', 'published'));
        $this->assertTrue(RecruitmentEmail::isCampaignEligible('careers@company.com', 'published'));
    }
}
