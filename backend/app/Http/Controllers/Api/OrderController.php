<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\FefoAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index()
    {
        return response()->json(['status' => 'success', 'data' => Order::with('items.product', 'items.allocations.batch')->latest()->paginate(20)]);
    }

    public function show(Order $order)
    {
        return response()->json(['status' => 'success', 'data' => $order->load('items.product', 'items.allocations.batch')]);
    }

    public function store(Request $request, FefoAllocationService $allocationService)
    {
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        $allocationService->assertAvailable($data['items']);

        $order = DB::transaction(function () use ($data, $request) {
            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::query()->whereIn('id', $productIds)->where('is_active', true)->get()->keyBy('id');
            $total = 0;
            foreach ($data['items'] as $item) {
                $total += round((float) $item['quantity'] * (float) $products[$item['product_id']]->base_price, 2);
            }

            $order = Order::create([
                'user_id' => $request->user()->id,
                'order_code' => 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                'order_type' => 'retail',
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'delivery_address' => $data['delivery_address'],
                'shipping_fee' => 0,
                'status' => 'pending',
                'total_amount' => $total,
            ]);

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->base_price,
                ]);
            }

            return $order->load('items.product');
        });

        return response()->json(['status' => 'success', 'data' => $order], 201);
    }

    public function confirm(Request $request, Order $order, FefoAllocationService $allocationService)
    {
        $confirmedOrder = $allocationService->confirm($order);
        return response()->json(['status' => 'success', 'data' => $confirmedOrder]);
    }
}
