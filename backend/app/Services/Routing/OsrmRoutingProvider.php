<?php

namespace App\Services\Routing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OsrmRoutingProvider implements RoutingProvider
{
    public function assertReady(): void
    {
        $this->routingConfig();
    }

    public function distanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): ?float {
        [$baseUrl, $connectTimeout, $timeout] = $this->routingConfig();
        $url = rtrim($baseUrl, '/').'/route/v1/driving/'
            .$this->coordinate($originLongitude).','.$this->coordinate($originLatitude).';'
            .$this->coordinate($destinationLongitude).','.$this->coordinate($destinationLatitude);

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'OrganicFarmEcommerce/1.0'])
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
            return null;
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
