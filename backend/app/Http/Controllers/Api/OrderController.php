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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'shipping_address' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);

        $allocationService->assertAvailable($data['items']);

        $order = DB::transaction(function () use ($data) {
            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::query()->whereIn('id', $productIds)->where('is_active', true)->get()->keyBy('id');
            $total = 0;
            foreach ($data['items'] as $item) {
                $total += round((float) $item['quantity'] * (float) $products[$item['product_id']]->price, 2);
            }

            $order = Order::create([
                'user_id' => auth()->id(),
                'order_number' => 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'status' => 'pending',
                'total_amount' => $total,
            ]);

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'line_total' => round($quantity * (float) $product->price, 2),
                ]);
            }

            return $order->load('items.product');
        });

        return response()->json(['status' => 'success', 'data' => $order], 201);
    }

    public function confirm(Request $request, Order $order, FefoAllocationService $allocationService)
    {
        $confirmedOrder = $allocationService->confirm($order, (int) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $confirmedOrder]);
    }
}
