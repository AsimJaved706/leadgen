<?php

namespace Tests\Unit;

use App\Support\GoogleMapsLeadNormalizer;
use PHPUnit\Framework\TestCase;

class GoogleMapsLeadNormalizerTest extends TestCase
{
    public function test_it_normalizes_extractor_rows_and_recovers_embedded_values(): void
    {
        $lead = GoogleMapsLeadNormalizer::normalize([
            'Name' => 'Sponsored',
            'ListingDetails' => "HEEDGROUP\nSponsored\n4.8(1,234)\nMarketing agency",
            'Email' => 'hello@example.com, sales@example.com',
            'Phone' => 'phone:',
            'AdditionalDetails_phone' => '(646) 822-2911',
            'Address' => '500 7th Avenue, New York, NY 10018',
            'CID' => '12345',
            'MapsURL' => 'https://maps.google.com/example',
            'Instagram' => 'https://instagram.com/example, https://instagram.com/example/posts',
            'WeeklyHours_Monday' => '9 AM–6 PM ',
        ]);

        $this->assertSame('HEEDGROUP', $lead['name']);
        $this->assertSame('hello@example.com', $lead['email']);
        $this->assertSame(['sales@example.com'], $lead['additional_emails']);
        $this->assertSame('(646) 822-2911', $lead['phone']);
        $this->assertSame('500 7th Avenue, New York, NY 10018', $lead['address']);
        $this->assertSame('New York', $lead['city']);
        $this->assertSame(4.8, $lead['average_rating']);
        $this->assertSame(1234, $lead['review_count']);
        $this->assertSame('9 AM–6 PM', $lead['weekly_hours']['monday']);
    }

    public function test_it_normalizes_phone_only_crm_leads(): void
    {
        $lead = GoogleMapsLeadNormalizer::normalize([
            'name' => '4692851888',
            'email' => '4692851888@kvleads.com',
            'cell_phone_1' => '4692851888',
            'source' => 'Tracked Call',
        ]);

        $this->assertSame('Phone lead · 4692851888', $lead['name']);
        $this->assertSame('4692851888', $lead['phone']);
        $this->assertSame('4692851888', $lead['normalized_phone']);
    }
}
