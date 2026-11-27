<?php

namespace App\Services\Geocoding;

interface GeocodingProvider
{
    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array;
}
