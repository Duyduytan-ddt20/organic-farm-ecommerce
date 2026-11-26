<?php

namespace App\Services\Geocoding;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class NominatimGeocodingProvider implements GeocodingProvider
{
    private const RETRY_ATTEMPTS = 2;

    private const RETRY_DELAY_MILLISECONDS = 1100;

    public function geocode(string $address): ?array
    {
        $baseUrl = config('services.nominatim.base_url');
        $userAgent = config('services.nominatim.user_agent');
        $connectTimeout = $this->timeout('services.shipping.connect_timeout');
        $timeout = $this->timeout('services.shipping.timeout');

        if (! $this->isHttpsUrl($baseUrl) || ! is_string($userAgent) || trim($userAgent) === '') {
            throw new HttpException(503, 'Cấu hình Nominatim chưa đầy đủ hoặc không hợp lệ.');
        }

        $response = $this->sendRequest(
            rtrim($baseUrl, '/').'/search',
            $userAgent,
            $connectTimeout,
            $timeout,
            $address,
        );

        if ($response->status() === 429) {
            throw new HttpException(503, 'Nominatim đang giới hạn yêu cầu. Vui lòng thử lại sau.');
        }

        if (! $response->successful()) {
            throw new HttpException(502, 'Dịch vụ geocoding không khả dụng.');
        }

        $results = $response->json();
        if (! is_array($results) || ! array_is_list($results)) {
            throw new HttpException(502, 'Dịch vụ geocoding trả dữ liệu không hợp lệ.');
        }

        if ($results === []) {
            return null;
        }

        $result = $results[0] ?? null;
        if (! is_array($result) || ! is_numeric($result['lat'] ?? null) || ! is_numeric($result['lon'] ?? null)) {
            throw new HttpException(502, 'Dịch vụ geocoding trả tọa độ không hợp lệ.');
        }

        $latitude = (float) $result['lat'];
        $longitude = (float) $result['lon'];

        if (
            ! is_finite($latitude) ||
            ! is_finite($longitude) ||
            $latitude < -90 ||
            $latitude > 90 ||
            $longitude < -180 ||
            $longitude > 180
        ) {
            throw new HttpException(502, 'Dịch vụ geocoding trả tọa độ ngoài phạm vi hợp lệ.');
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function sendRequest(
        string $url,
        string $userAgent,
        int $connectTimeout,
        int $timeout,
        string $address,
    ): Response {
        $lockSeconds = max(
            15,
            ($timeout * self::RETRY_ATTEMPTS)
                + (int) ceil(self::RETRY_DELAY_MILLISECONDS / 1000)
                + 5,
        );

        try {
            return Cache::lock('shipping:nominatim:request-lock', $lockSeconds)
                ->block($lockSeconds, function () use ($url, $userAgent, $connectTimeout, $timeout, $address): Response {
                    $lastRequestAt = Cache::get('shipping:nominatim:last-request-at');
                    if (is_numeric($lastRequestAt)) {
                        $remainingMicroseconds = (int) ceil(
                            (1 - (microtime(true) - (float) $lastRequestAt)) * 1_000_000
                        );

                        if ($remainingMicroseconds > 0) {
                            usleep($remainingMicroseconds);
                        }
                    }

                    Cache::put('shipping:nominatim:last-request-at', microtime(true), now()->addMinute());

                    return Http::acceptJson()
                        ->withHeaders(['User-Agent' => $userAgent])
                        ->connectTimeout($connectTimeout)
                        ->timeout($timeout)
                        ->retry(
                            self::RETRY_ATTEMPTS,
                            self::RETRY_DELAY_MILLISECONDS,
                            static fn (Throwable $exception): bool => (
                                $exception instanceof ConnectionException
                                || (
                                    $exception instanceof RequestException
                                    && $exception->response->serverError()
                                )
                            ),
                            throw: false,
                        )
                        ->get($url, [
                            'q' => $address,
                            'format' => 'jsonv2',
                            'limit' => 1,
                        ]);
                });
        } catch (ConnectionException $exception) {
            throw new HttpException(504, 'Geocoding đã hết thời gian chờ.', $exception);
        } catch (LockTimeoutException $exception) {
            throw new HttpException(503, 'Geocoding đang bận do giới hạn tần suất. Vui lòng thử lại sau.', $exception);
        }
    }

    private function timeout(string $key): int
    {
        $value = config($key);
        $timeout = filter_var($value, FILTER_VALIDATE_INT);

        if ($timeout === false || $timeout < 1) {
            throw new HttpException(503, 'Cấu hình timeout cho dịch vụ geocoding không hợp lệ.');
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
