<?php

namespace App\Services\Geocoding;

class MockGeocodingProvider implements GeocodingProvider
{
    private const ADDRESSES = [
        '100 mock street, example district' => [
            'latitude' => 10.8,
            'longitude' => 106.75,
        ],
        '200 mock street, example district' => [
            'latitude' => 10.82,
            'longitude' => 106.72,
        ],
        '999 mock street, no route' => [
            'latitude' => 10.9,
            'longitude' => 106.9,
        ],
    ];

    public function geocode(string $address): ?array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($address));
        if (! is_string($normalized)) {
            return null;
        }

        return self::ADDRESSES[mb_strtolower($normalized)] ?? null;
    }
}
