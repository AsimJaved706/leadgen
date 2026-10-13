<?php

namespace Tests\Unit;

use App\Support\JobCountryPolicy;
use PHPUnit\Framework\TestCase;

class JobCountryPolicyTest extends TestCase
{
    public function test_asian_countries_and_aliases_are_excluded(): void
    {
        foreach (['Pakistan', 'India', 'Singapore', 'Japan', 'UAE', 'United Arab Emirates', 'Philippines'] as $country) {
            $this->assertTrue(JobCountryPolicy::isAsian($country));
        }
    }

    public function test_target_markets_and_unspecified_remote_jobs_are_allowed(): void
    {
        foreach (['United States', 'Canada', 'United Kingdom', 'Germany', 'Australia', 'Brazil'] as $country) {
            $this->assertFalse(JobCountryPolicy::isAsian($country));
        }
        $this->assertFalse(JobCountryPolicy::isAsian(null, 'Remote'));
        $this->assertFalse(JobCountryPolicy::isAsian(null, 'Worldwide'));
        $this->assertTrue(JobCountryPolicy::isAsian(null, 'Lahore, Pakistan'));
    }
}
