<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateShippingRequest;
use App\Services\ShippingQuoteTokenService;
use App\Services\ShippingService;
use Illuminate\Http\JsonResponse;

class ShippingController extends Controller
{
    public function calculate(
        CalculateShippingRequest $request,
        ShippingService $shipping,
        ShippingQuoteTokenService $quoteTokens,
    ): JsonResponse {
        $deliveryAddress = $request->validated('delivery_address');
        $quote = $shipping->calculate($deliveryAddress);
        $quote['quote_token'] = $quoteTokens->issue(
            $deliveryAddress,
            $quote['shipping_fee'],
            (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json($quote);
    }
}
