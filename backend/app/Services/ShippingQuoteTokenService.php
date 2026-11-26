<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShippingQuoteTokenService
{
    public function issue(string $deliveryAddress, int $shippingFee, int $userId): string
    {
        $ttl = $this->ttl();
        $payload = json_encode([
            'version' => 1,
            'delivery_address' => $this->normalizeAddress($deliveryAddress),
            'shipping_fee' => $shippingFee,
            'user_id' => $userId,
            'expires_at' => now()->addSeconds($ttl)->timestamp,
        ], JSON_THROW_ON_ERROR);

        return Crypt::encryptString($payload);
    }

    /**
     * @return array{shipping_fee: int}
     */
    public function verify(string $token, string $deliveryAddress, int $userId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->invalidQuote();
        }

        if (
            ! is_array($payload) ||
            ($payload['version'] ?? null) !== 1 ||
            ! is_string($payload['delivery_address'] ?? null) ||
            ! is_int($payload['shipping_fee'] ?? null) ||
            $payload['shipping_fee'] < 0 ||
            ! is_int($payload['user_id'] ?? null) ||
            ! is_int($payload['expires_at'] ?? null)
        ) {
            $this->invalidQuote();
        }

        if ($payload['expires_at'] <= now()->timestamp) {
            throw ValidationException::withMessages([
                'quote_token' => 'Báo giá vận chuyển đã hết hạn. Vui lòng tính lại.',
            ]);
        }

        if ($payload['user_id'] !== $userId) {
            $this->invalidQuote();
        }

        if (! hash_equals($payload['delivery_address'], $this->normalizeAddress($deliveryAddress))) {
            throw ValidationException::withMessages([
                'quote_token' => 'Báo giá vận chuyển không khớp địa chỉ giao hàng. Vui lòng tính lại.',
            ]);
        }

        return ['shipping_fee' => $payload['shipping_fee']];
    }

    private function ttl(): int
    {
        $value = config('services.shipping.quote_ttl');
        $ttl = filter_var($value, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 1) {
            throw new HttpException(503, 'Cấu hình thời hạn báo giá vận chuyển không hợp lệ.');
        }

        return $ttl;
    }

    private function normalizeAddress(string $deliveryAddress): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($deliveryAddress));

        return is_string($normalized) ? $normalized : '';
    }

    private function invalidQuote(): never
    {
        throw ValidationException::withMessages([
            'quote_token' => 'Báo giá vận chuyển không hợp lệ. Vui lòng tính lại.',
        ]);
    }
}
