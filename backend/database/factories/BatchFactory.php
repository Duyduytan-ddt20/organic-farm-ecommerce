<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        $plantedAt = now()->subDays(rand(25, 40))->toDateString();
        $harvestedAt = now()->subDays(rand(1, 3))->toDateString();
        $receivedQty = fake()->randomFloat(2, 20, 100);

        return [
            'product_id' => Product::inRandomOrder()->value('id') ?? Product::factory(),
            'batch_code' => 'LOT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4)),
            'growing_location' => 'Khu ' . fake()->randomElement(['A', 'B', 'C']) . ' - Luống ' . rand(1, 20),
            'planted_at' => $plantedAt,
            'harvested_at' => $harvestedAt,
            'expires_at' => now()->addDays(rand(5, 10)),
            'received_quantity' => $receivedQty,
            'available_quantity' => $receivedQty,
            'status' => 'available',
            'qr_token' => Str::random(32),
        ];
    }

    /**
     * Trạng thái lô đã bán hết (tồn kho khả dụng = 0)
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'available_quantity' => 0,
            'status' => 'closed',
        ]);
    }

    /**
     * Trạng thái lô đã quá hạn sử dụng
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'planted_at' => now()->subDays(35)->toDateString(),
            'harvested_at' => now()->subDays(10)->toDateString(),
            'expires_at' => now()->subDays(2),
            'available_quantity' => 15.00,
            'status' => 'available',
        ]);
    }

    /**
     * Trạng thái lô sắp hết hạn (dưới ngưỡng cảnh báo 48 giờ)
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'planted_at' => now()->subDays(30)->toDateString(),
            'harvested_at' => now()->subDays(4)->toDateString(),
            'expires_at' => now()->addHours(24),
            'available_quantity' => 12.00,
            'status' => 'available',
        ]);
    }
}