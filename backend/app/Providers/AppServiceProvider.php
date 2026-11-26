<?php

namespace App\Providers;

use App\Services\Geocoding\GeocodingProvider;
use App\Services\Geocoding\NominatimGeocodingProvider;
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
                default => throw new HttpException(503, 'Nhà cung cấp dịch vụ định vị chưa được cấu hình.'),
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
