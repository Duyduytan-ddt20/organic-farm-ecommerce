<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $order = Order::firstOrCreate(
            ['order_code' => 'ORD-DEMO-DELIVERED'],
            [
                'user_id' => 4,
                'order_type' => 'retail',
                'recipient_name' => 'Khách hàng Mẫu',
                'recipient_phone' => '0901000004',
                'delivery_address' => '1 Đường Nông Trại',
                'shipping_fee' => 0,
                'total_amount' => 90000,
                'status' => 'delivered',
                'delivered_at' => $now,
            ],
        );

        $order->items()->firstOrCreate(
            ['product_id' => 1],
            ['quantity' => 2, 'unit_price' => 45000],
        );
    }
}
