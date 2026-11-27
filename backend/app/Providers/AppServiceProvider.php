<?php

namespace App\Providers;

use App\Services\Geocoding\GeocodingProvider;
use App\Services\Geocoding\MockGeocodingProvider;
use App\Services\Geocoding\NominatimGeocodingProvider;
use App\Services\Routing\MockRoutingProvider;
use App\Services\Routing\OsrmRoutingProvider;
use App\Services\Routing\RoutingProvider;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GeocodingProvider::class, function ($app): GeocodingProvider {
            return match (config('services.shipping.provider')) {
                'nominatim' => $app->make(NominatimGeocodingProvider::class),
                'mock' => $app->environment(['local', 'testing'])
                    ? $app->make(MockGeocodingProvider::class)
                    : throw new HttpException(503, 'Mock geocoding chỉ được dùng trong môi trường local và testing.'),
                default => throw new HttpException(503, 'Nhà cung cấp dịch vụ định vị chưa được cấu hình.'),
            };
        });

        $this->app->bind(RoutingProvider::class, function ($app): RoutingProvider {
            return match (config('services.shipping.routing_provider')) {
                'osrm' => $app->make(OsrmRoutingProvider::class),
                'mock' => $app->environment(['local', 'testing'])
                    ? $app->make(MockRoutingProvider::class)
                    : throw new HttpException(503, 'Mock routing chỉ được dùng trong môi trường local và testing.'),
                default => throw new HttpException(503, 'Nhà cung cấp dịch vụ định tuyến chưa được cấu hình.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
