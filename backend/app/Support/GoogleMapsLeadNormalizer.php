<?php

namespace App\Support;

use Carbon\Carbon;

class GoogleMapsLeadNormalizer
{
    public static function normalize(array $row): array
    {
        $value = fn (string ...$keys) => self::first($row, $keys);
        $listing = $value('ListingDetails', 'listingDetails') ?? '';
        $name = self::cleanText($value('Name', 'name'));
        if (! $name || preg_match('/^(sponsored|ad|)+$/iu', $name)) {
            $name = self::nameFromListing($listing);
        }
        if (! $name) {
            throw new \InvalidArgumentException('A business name could not be found.');
        }

        $emails = self::split($value('Email', 'email'));
        $emails = array_values(array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
        $phones = self::phones($row);
        if (preg_match('/^\+?\d{7,15}$/', preg_replace('/[\s().-]/', '', $name)) && isset($phones[0])) {
            $name = 'Phone lead · '.$phones[0];
        }
        $website = self::url($value('Website', 'website', 'ActionLinks_website'));
        $address = self::cleanAddress($value('AdditionalDetails_address', 'Address', 'address'));
        [$city, $state, $postal, $country] = self::location($address);
        $rating = self::number($value('AverageRating', 'averageRating', 'rating'));
        $reviews = self::integer($value('ReviewCount', 'reviewCount', 'reviews'));
        if ($rating === null && preg_match('/(?m)^([0-5](?:\.\d)?)\s*\(([\d,]+)\)/u', $listing, $match)) {
            $rating = (float) $match[1];
            $reviews ??= (int) str_replace(',', '', $match[2]);
        }

        $social = [];
        foreach (['Instagram', 'Facebook', 'Twitter', 'Linkedin', 'Yelp', 'Youtube', 'Pinterest', 'Tiktok', 'Whatsapp'] as $network) {
            $links = array_values(array_filter(array_map([self::class, 'url'], self::split($value($network, strtolower($network))))));
            if ($links) {
                $social[strtolower($network)] = array_values(array_unique($links));
            }
        }
        $hours = [];
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            if ($entry = self::cleanText($value("WeeklyHours_$day"))) {
                $hours[strtolower($day)] = trim(str_replace('', '', $entry));
            }
        }
        $images = [];
        for ($i = 0; $i < 20; $i++) {
            if ($url = self::url($value("ImageUrls_$i"))) {
                $images[] = $url;
            }
        }
        $actions = array_filter([
            'website' => self::url($value('ActionLinks_website')),
            'reserve' => self::url($value('ActionLinks_reserve', 'ReservationURL')),
        ]);

        return array_filter([
            'name' => $name,
            'phone' => $phones[0] ?? null,
            'secondary_phones' => array_slice($phones, 1),
            'normalized_phone' => isset($phones[0]) ? preg_replace('/\D/', '', $phones[0]) : null,
            'email' => $emails[0] ?? null,
            'additional_emails' => array_slice($emails, 1),
            'website' => $website,
            'website_domain' => $website ? preg_replace('/^www\./', '', strtolower(parse_url($website, PHP_URL_HOST) ?? '')) : null,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'postal_code' => $postal,
            'country' => $country,
            'category' => self::cleanText($value('Category', 'category')),
            'categories' => array_values(array_unique(self::split($value('Category', 'category')))),
            'place_id' => self::cleanText($value('PlaceID', 'placeID', 'place_id')),
            'cid' => self::cleanText($value('CID', 'cid')),
            'average_rating' => $rating,
            'review_count' => $reviews ?? 0,
            'latitude' => self::number($value('Latitude', 'latitude')),
            'longitude' => self::number($value('Longitude', 'longitude')),
            'google_maps_url' => self::url($value('MapsURL', 'mapsURL', 'google_maps_url')),
            'reservation_url' => self::url($value('ReservationURL', 'ActionLinks_reserve')),
            'weekly_hours' => $hours,
            'social_profiles' => $social,
            'image_urls' => array_values(array_unique($images)),
            'action_links' => $actions,
            'additional_details' => array_filter([
                'ownership' => self::cleanText($value('AdditionalDetails_place_info_links')),
                'hours_text' => self::cleanText($value('Hours')),
                'details_collected' => filter_var($value('DetailsCollected'), FILTER_VALIDATE_BOOLEAN),
                'updated_at_source' => self::date($value('UpdatedAt')),
            ], fn ($v) => $v !== null && $v !== '' && $v !== false),
            'raw_maps_details' => ['listing' => $listing ?: null, 'details' => $value('RawDetails'), 'source' => $row],
            'collected_at' => self::date($value('CollectedAt')),
            'enriched_at' => self::date($value('EnrichedAt', 'DetailsCollectedAt')),
            'name_address_hash' => $address ? hash('sha256', mb_strtolower(trim($name).'|'.trim($address))) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function first(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && ! is_array($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private static function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/[\x{E000}-\x{F8FF}]/u', '', (string) $value));

        return $value === '' ? null : $value;
    }

    private static function cleanAddress(mixed $value): ?string
    {
        return self::cleanText($value);
    }

    private static function nameFromListing(string $listing): ?string
    {
        foreach (preg_split('/\R/u', $listing) as $line) {
            $line = self::cleanText($line);
            if ($line && ! preg_match('/^(sponsored|visited link|no reviews)$/i', $line)) {
                return $line;
            }
        }

        return null;
    }

    private static function split(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $value))));
    }

    private static function phones(array $row): array
    {
        $values = [];
        foreach ($row as $key => $value) {
            $normalizedKey = strtolower($key);
            $isPhoneField = in_array($normalizedKey, ['phone', 'cell_phone_1', 'cell_phone_2', 'home_phone', 'work_phone', 'mobile', 'mobile_phone', 'additionaldetails_phone'], true)
                || str_starts_with($normalizedKey, 'additionaldetails_phone_');
            if ($isPhoneField && $value) {
                foreach (self::split($value) as $phone) {
                    if (preg_match('/\d{7,}/', preg_replace('/\D/', '', $phone))) {
                        $values[] = trim($phone);
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }

    private static function url(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        $value = trim(html_entity_decode(str_replace('\\u0026', '&', (string) $value)));

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        $value = str_replace(',', '', (string) $value);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function location(?string $address): array
    {
        if (! $address) {
            return [null, null, null, null];
        }
        if (preg_match('/,\s*([^,]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)(?:,\s*(.+))?$/', $address, $m)) {
            return [trim($m[1]), $m[2], $m[3], trim($m[4] ?? 'United States')];
        }

        return [null, null, null, null];
    }
}
