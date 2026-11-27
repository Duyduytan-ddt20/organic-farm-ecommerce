<?php

namespace App\Services\Routing;

interface RoutingProvider
{
    public function assertReady(): void;

    public function distanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): ?float;
}
