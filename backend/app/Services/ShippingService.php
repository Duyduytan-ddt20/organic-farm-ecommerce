<?php

namespace App\Services;

use App\Services\Geocoding\GeocodingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShippingService
{
    public function __construct(private readonly GeocodingProvider $geocoder) {}

    /**
     * @return array{distance_km: float, shipping_rate: int, shipping_fee: int}
     */
    public function calculate(string $deliveryAddress): array
    {
        $this->ensureExternalServicesEnabled();
        $address = $this->normalizeAddress($deliveryAddress);
        $ttl = $this->cacheTtl();
        [$originLatitude, $originLongitude] = $this->originCoordinates();
        $rate = $this->shippingRate();
        [$baseUrl, $connectTimeout, $timeout] = $this->routingConfig();
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

        $distanceMeters = $this->roadDistanceMeters(
            $originLatitude,
            $originLongitude,
            (float) $latitude,
            (float) $longitude,
            $baseUrl,
            $connectTimeout,
            $timeout,
        );

        return [
            'distance_km' => round($distanceMeters / 1000, 3, PHP_ROUND_HALF_UP),
            'shipping_rate' => $rate,
            'shipping_fee' => (int) round($distanceMeters * $rate / 1000, 0, PHP_ROUND_HALF_UP),
        ];
    }

    private function ensureExternalServicesEnabled(): void
    {
        if (! config('services.shipping.external_enabled')) {
            throw new HttpException(503, 'Dịch vụ tính phí vận chuyển bên ngoài hiện đang tắt.');
        }

        if (
            config('services.shipping.provider') === 'nominatim' &&
            ! app()->environment(['local', 'testing'])
        ) {
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

    private function roadDistanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
        string $baseUrl,
        int $connectTimeout,
        int $timeout,
    ): float {
        $url = rtrim($baseUrl, '/').'/route/v1/driving/'
            .$this->coordinate($originLongitude).','.$this->coordinate($originLatitude).';'
            .$this->coordinate($destinationLongitude).','.$this->coordinate($destinationLatitude);

        try {
            $response = Http::acceptJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get($url, ['overview' => 'false']);
        } catch (ConnectionException $exception) {
            throw new HttpException(504, 'Dịch vụ định tuyến đã hết thời gian chờ.', $exception);
        }

        if ($response->status() === 429) {
            throw new HttpException(503, 'Dịch vụ định tuyến đang giới hạn yêu cầu. Vui lòng thử lại sau.');
        }

        if (! $response->successful()) {
            throw new HttpException(502, 'Dịch vụ định tuyến không khả dụng.');
        }

        $result = $response->json();
        if (! is_array($result)) {
            throw new HttpException(502, 'Dịch vụ định tuyến trả dữ liệu không hợp lệ.');
        }

        if (in_array($result['code'] ?? null, ['NoRoute', 'NoSegment'], true)) {
            throw ValidationException::withMessages([
                'delivery_address' => 'Không tìm thấy tuyến đường đến địa chỉ giao hàng.',
            ]);
        }

        $routes = $result['routes'] ?? null;
        $route = is_array($routes) ? ($routes[0] ?? null) : null;
        if (($result['code'] ?? null) !== 'Ok' || ! is_array($route) || ! array_key_exists('distance', $route)) {
            throw new HttpException(502, 'Dịch vụ định tuyến trả tuyến đường không hợp lệ.');
        }

        $distance = $route['distance'];
        if (! is_numeric($distance) || ! is_finite((float) $distance) || (float) $distance < 0) {
            throw new HttpException(502, 'Dịch vụ định tuyến trả khoảng cách không hợp lệ.');
        }

        return (float) $distance;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function routingConfig(): array
    {
        $baseUrl = config('services.osrm.base_url');
        if (! $this->isHttpsUrl($baseUrl)) {
            throw new HttpException(503, 'Cấu hình URL OSRM chưa đầy đủ hoặc không hợp lệ.');
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === 'router.project-osrm.org' && ! app()->environment(['local', 'testing'])) {
            throw new HttpException(503, 'OSRM Demo chỉ được sử dụng trong môi trường phát triển và kiểm thử.');
        }

        return [
            $baseUrl,
            $this->timeout('services.shipping.connect_timeout'),
            $this->timeout('services.shipping.timeout'),
        ];
    }

    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }

    private function timeout(string $key): int
    {
        $value = config($key);
        $timeout = filter_var($value, FILTER_VALIDATE_INT);

        if ($timeout === false || $timeout < 1) {
            throw new HttpException(503, 'Cấu hình timeout cho dịch vụ định tuyến không hợp lệ.');
        }

        return $timeout;
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
