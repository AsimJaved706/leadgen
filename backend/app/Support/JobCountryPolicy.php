<?php

namespace App\Support;

use Illuminate\Support\Str;

final class JobCountryPolicy
{
    private const ASIAN_COUNTRIES = [
        'afghanistan', 'armenia', 'azerbaijan', 'bahrain', 'bangladesh', 'bhutan', 'brunei', 'cambodia',
        'china', 'cyprus', 'georgia', 'india', 'indonesia', 'iran', 'iraq', 'israel', 'japan', 'jordan',
        'kazakhstan', 'kuwait', 'kyrgyzstan', 'laos', 'lebanon', 'malaysia', 'maldives', 'mongolia',
        'myanmar', 'nepal', 'north korea', 'oman', 'pakistan', 'palestine', 'philippines', 'qatar',
        'saudi arabia', 'singapore', 'south korea', 'sri lanka', 'syria', 'taiwan', 'tajikistan',
        'thailand', 'timor-leste', 'turkey', 'turkmenistan', 'united arab emirates', 'uzbekistan',
        'vietnam', 'yemen', 'hong kong', 'macao', 'macau',
    ];

    private const ALIASES = [
        'uae' => 'united arab emirates', 'u.a.e.' => 'united arab emirates',
        'republic of korea' => 'south korea', 'korea' => 'south korea',
        'viet nam' => 'vietnam', 'burma' => 'myanmar', 'brunei darussalam' => 'brunei',
    ];

    public static function isAsian(?string $country, ?string $location = null): bool
    {
        $country = self::clean($country);
        if ($country !== '') {
            $country = self::ALIASES[$country] ?? $country;
            return in_array($country, self::ASIAN_COUNTRIES, true);
        }

        $location = self::clean($location);
        if ($location === '' || in_array($location, ['remote', 'worldwide', 'global', 'anywhere'], true)) return false;
        foreach ([...self::ASIAN_COUNTRIES, ...array_keys(self::ALIASES)] as $name) {
            if (preg_match('/(^|,|\\s)'.preg_quote($name, '/').'($|,|\\s)/i', $location)) return true;
        }
        return false;
    }

    private static function clean(?string $value): string
    {
        return Str::of((string) $value)->lower()->replace(['_', '-'], ' ')->squish()->value();
    }
}
