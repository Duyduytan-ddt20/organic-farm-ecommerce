<?php

namespace App\Services\Routing;

class MockRoutingProvider implements RoutingProvider
{
    private const ROUTES = [
        '10.800000,106.750000' => 5234.0,
        '10.820000,106.720000' => 8500.0,
    ];

    public function assertReady(): void {}

    public function distanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): ?float {
        if (
            abs($originLatitude - 10.77197) > 0.000001 ||
            abs($originLongitude - 106.70135) > 0.000001
        ) {
            return null;
        }

        $destination = number_format($destinationLatitude, 6, '.', '').','
            .number_format($destinationLongitude, 6, '.', '');

        return self::ROUTES[$destination] ?? null;
    }
}
