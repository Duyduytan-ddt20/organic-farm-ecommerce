<?php

namespace App\Services;

use App\Services\Geocoding\GeocodingProvider;
use App\Services\Routing\RoutingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShippingService
{
    public function __construct(
        private readonly GeocodingProvider $geocoder,
        private readonly RoutingProvider $router,
    ) {}

    /**
     * @return array{distance_km: float, shipping_rate: int, shipping_fee: int}
     */
    public function calculate(string $deliveryAddress): array
    {
        $this->ensureProvidersEnabled();
        $address = $this->normalizeAddress($deliveryAddress);
        $ttl = $this->cacheTtl();
        [$originLatitude, $originLongitude] = $this->originCoordinates();
        $rate = $this->shippingRate();
        $this->router->assertReady();
        $provider = (string) config('services.shipping.provider');
        $cacheKey = 'shipping:geocode:'.$provider.':'.hash('sha256', mb_strtolower($address));

        $coordinates = Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn (): ?array => $this->geocoder->geocode($address),
        );

        if ($coordinates === null) {
            throw ValidationException::withMessages([
                'delivery_address' => 'Không tìm thấy tọa độ cho địa chỉ giao hàng.',
            ]);
        }

        $latitude = $coordinates['latitude'] ?? null;
        $longitude = $coordinates['longitude'] ?? null;
        if (
            ! is_numeric($latitude) ||
            ! is_numeric($longitude) ||
            ! is_finite((float) $latitude) ||
            ! is_finite((float) $longitude) ||
            (float) $latitude < -90 ||
            (float) $latitude > 90 ||
            (float) $longitude < -180 ||
            (float) $longitude > 180
        ) {
            throw new HttpException(502, 'Dịch vụ geocoding trả tọa độ không hợp lệ.');
        }

        $distanceMeters = $this->router->distanceMeters(
            $originLatitude,
            $originLongitude,
            (float) $latitude,
            (float) $longitude,
        );
        if ($distanceMeters === null) {
            throw ValidationException::withMessages([
                'delivery_address' => 'Không tìm thấy tuyến đường đến địa chỉ giao hàng.',
            ]);
        }
        if (! is_finite($distanceMeters) || $distanceMeters < 0) {
            throw new HttpException(502, 'Dịch vụ định tuyến trả khoảng cách không hợp lệ.');
        }

        return [
            'distance_km' => round($distanceMeters / 1000, 3, PHP_ROUND_HALF_UP),
            'shipping_rate' => $rate,
            'shipping_fee' => (int) round($distanceMeters * $rate / 1000, 0, PHP_ROUND_HALF_UP),
        ];
    }

    private function ensureProvidersEnabled(): void
    {
        $geocodingProvider = config('services.shipping.provider');
        $routingProvider = config('services.shipping.routing_provider');
        $usesMock = $geocodingProvider === 'mock' || $routingProvider === 'mock';

        if ($usesMock && ! app()->environment(['local', 'testing'])) {
            throw new HttpException(503, 'Mock shipping chỉ được dùng trong môi trường local và testing.');
        }

        if (
            ($geocodingProvider !== 'mock' || $routingProvider !== 'mock') &&
            ! config('services.shipping.external_enabled')
        ) {
            throw new HttpException(503, 'Dịch vụ tính phí vận chuyển bên ngoài hiện đang tắt.');
        }

        if ($geocodingProvider === 'nominatim' && ! app()->environment(['local', 'testing'])) {
            throw new HttpException(503, 'Nominatim chỉ được bật trong môi trường phát triển và kiểm thử.');
        }
    }

    private function normalizeAddress(string $address): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($address));

        if (! is_string($normalized) || $normalized === '') {
            throw ValidationException::withMessages([
                'delivery_address' => 'Vui lòng nhập địa chỉ giao hàng hợp lệ.',
            ]);
        }

        return $normalized;
    }

    private function cacheTtl(): int
    {
        $value = config('services.shipping.geocode_cache_ttl');
        $ttl = filter_var($value, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 1) {
            throw new HttpException(503, 'Cấu hình thời hạn cache geocoding không hợp lệ.');
        }

        return $ttl;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function originCoordinates(): array
    {
        $latitude = config('services.shipping.farm_latitude');
        $longitude = config('services.shipping.farm_longitude');

        if (
            ! is_numeric($latitude) ||
            ! is_numeric($longitude) ||
            ! is_finite((float) $latitude) ||
            ! is_finite((float) $longitude) ||
            (float) $latitude < -90 ||
            (float) $latitude > 90 ||
            (float) $longitude < -180 ||
            (float) $longitude > 180
        ) {
            throw new HttpException(503, 'Cấu hình tọa độ điểm xuất phát bị thiếu hoặc không hợp lệ.');
        }

        return [(float) $latitude, (float) $longitude];
    }

    private function shippingRate(): int
    {
        $value = config('services.shipping.rate_per_km');
        $rate = filter_var($value, FILTER_VALIDATE_INT);

        if ($rate === false || $rate < 1) {
            throw new HttpException(503, 'Cấu hình đơn giá vận chuyển bị thiếu hoặc không hợp lệ.');
        }

        return $rate;
    }
}
