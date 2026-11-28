<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentGatewayService
{
    public function createPaymentUrl(string $method, Payment $payment, Request $request): string
    {
        return match ($method) {
            'vnpay_sandbox' => $this->createVnpayUrl($payment, $request),
            'momo_sandbox' => $this->createMomoUrl($payment),
            default => throw new RuntimeException('Phương thức thanh toán không được hỗ trợ.'),
        };
    }

    public function verifyVnpayIpn(array $payload): ?array
    {
        $secret = config('services.vnpay.hash_secret');
        $secureHash = $payload['vnp_SecureHash'] ?? null;
        if (! is_string($secret) || $secret === '' || ! is_string($secureHash) || $secureHash === '') {
            return null;
        }

        unset($payload['vnp_SecureHash'], $payload['vnp_SecureHashType']);
        foreach ($payload as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                return null;
            }
            $payload[$key] = (string) ($value ?? '');
        }

        ksort($payload);
        $calculatedHash = hash_hmac('sha512', http_build_query($payload), $secret);
        if (! hash_equals($calculatedHash, $secureHash)) {
            return null;
        }

        $amount = $payload['vnp_Amount'] ?? '';
        $reference = $payload['vnp_TxnRef'] ?? '';
        if (! ctype_digit($amount) || $reference === '') {
            return null;
        }

        return [
            'payment_reference' => $reference,
            'amount_minor' => (int) $amount,
            'provider_transaction_id' => (string) ($payload['vnp_TransactionNo'] ?? ''),
            'succeeded' => ($payload['vnp_ResponseCode'] ?? '') === '00'
                && ($payload['vnp_TransactionStatus'] ?? '') === '00',
        ];
    }

    public function verifyMomoIpn(array $payload): ?array
    {
        $secret = config('services.momo.secret_key');
        $accessKey = config('services.momo.access_key');
        $configuredPartnerCode = config('services.momo.partner_code');
        if (! is_string($secret) || $secret === ''
            || ! is_string($accessKey) || $accessKey === ''
            || ! is_string($configuredPartnerCode) || $configuredPartnerCode === '') {
            return null;
        }

        $partnerCode = $configuredPartnerCode;
        $fields = [
            'accessKey' => $accessKey,
            'amount' => $payload['amount'] ?? null,
            'extraData' => $payload['extraData'] ?? null,
            'message' => $payload['message'] ?? null,
            'orderId' => $payload['orderId'] ?? null,
            'orderInfo' => $payload['orderInfo'] ?? null,
            'orderType' => $payload['orderType'] ?? null,
            'partnerCode' => $payload['partnerCode'] ?? null,
            'payType' => $payload['payType'] ?? null,
            'requestId' => $payload['requestId'] ?? null,
            'responseTime' => $payload['responseTime'] ?? null,
            'resultCode' => $payload['resultCode'] ?? null,
            'transId' => $payload['transId'] ?? null,
        ];

        if ((string) $fields['partnerCode'] !== $partnerCode) {
            return null;
        }

        foreach ($fields as $value) {
            if (! is_scalar($value) && $value !== null) {
                return null;
            }
        }

        $rawSignature = implode('&', array_map(
            fn (string $key, mixed $value) => $key.'='.(string) ($value ?? ''),
            array_keys($fields),
            array_values($fields)
        ));
        $calculatedSignature = hash_hmac('sha256', $rawSignature, $secret);
        $signature = $payload['signature'] ?? '';
        if (! is_string($signature) || ! hash_equals($calculatedSignature, $signature)) {
            return null;
        }

        $amount = $payload['amount'] ?? null;
        $reference = $payload['orderId'] ?? '';
        if (! is_scalar($amount) || ! ctype_digit((string) $amount) || ! is_string($reference) || $reference === '') {
            return null;
        }

        return [
            'payment_reference' => $reference,
            'amount_minor' => (int) $amount * 100,
            'provider_transaction_id' => (string) ($payload['transId'] ?? ''),
            'succeeded' => (string) ($payload['resultCode'] ?? '') === '0',
        ];
    }

    private function createVnpayUrl(Payment $payment, Request $request): string
    {
        $this->requireConfiguration([
            'services.vnpay.tmn_code',
            'services.vnpay.hash_secret',
            'services.vnpay.url',
            'services.vnpay.return_url',
        ], 'VNPay');

        $inputData = [
            'vnp_Version' => '2.1.0',
            'vnp_TmnCode' => config('services.vnpay.tmn_code'),
            'vnp_Amount' => (string) ((int) round((float) $payment->amount * 100)),
            'vnp_Command' => 'pay',
            'vnp_CreateDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
            'vnp_CurrCode' => 'VND',
            'vnp_IpAddr' => $request->ip(),
            'vnp_Locale' => 'vn',
            'vnp_OrderInfo' => 'Thanh toan don hang '.$payment->order_id,
            'vnp_OrderType' => 'billpayment',
            'vnp_ReturnUrl' => config('services.vnpay.return_url'),
            'vnp_TxnRef' => $payment->payment_reference,
        ];

        ksort($inputData);
        $query = http_build_query($inputData);
        $signature = hash_hmac('sha512', $query, config('services.vnpay.hash_secret'));

        return config('services.vnpay.url').'?'.$query.'&vnp_SecureHash='.$signature;
    }

    private function createMomoUrl(Payment $payment): string
    {
        $this->requireConfiguration([
            'services.momo.partner_code',
            'services.momo.access_key',
            'services.momo.secret_key',
            'services.momo.url',
            'services.momo.ipn_url',
            'services.momo.return_url',
        ], 'MoMo');

        $amount = (string) (int) round((float) $payment->amount);
        $partnerCode = config('services.momo.partner_code');
        $accessKey = config('services.momo.access_key');
        $requestId = $payment->payment_reference;
        $extraData = '';
        // 'payWithATM'
        $requestType = 'captureWallet';
        $orderInfo = 'Thanh toan don hang '.$payment->order_id;

        $signatureData = [
            'accessKey' => $accessKey,
            'amount' => $amount,
            'extraData' => $extraData,
            'ipnUrl' => config('services.momo.ipn_url'),
            'orderId' => $payment->payment_reference,
            'orderInfo' => $orderInfo,
            'partnerCode' => $partnerCode,
            'redirectUrl' => config('services.momo.return_url'),
            'requestId' => $requestId,
            'requestType' => $requestType,
        ];
        $rawSignature = implode('&', array_map(
            fn (string $key, string $value) => $key.'='.$value,
            array_keys($signatureData),
            array_values($signatureData)
        ));

        $payload = $signatureData + [
            'lang' => 'vi',
            'signature' => hash_hmac('sha256', $rawSignature, config('services.momo.secret_key')),
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(config('services.momo.url'), $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Không thể kết nối cổng MoMo Sandbox.', previous: $exception);
        }

        $responseData = $response->json();
        if (! $response->successful() || ! is_array($responseData)
            || (string) ($responseData['resultCode'] ?? '') !== '0'
            || ! filter_var($responseData['payUrl'] ?? null, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('MoMo Sandbox không thể tạo liên kết thanh toán.');
        }

        return $responseData['payUrl'];
    }

    private function requireConfiguration(array $keys, string $gateway): void
    {
        foreach ($keys as $key) {
            $value = config($key);
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException($gateway.' Sandbox chưa được cấu hình đầy đủ.');
            }
        }
    }
}
