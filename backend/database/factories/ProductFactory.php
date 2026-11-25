<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Xà lách thủy canh',
            'Cải bó xôi hữu cơ',
            'Ớt chuông đỏ Đà Lạt',
            'Cà chua bi hữu cơ',
            'Cải thìa 4KFarm',
            'Dưa leo baby',
            'Bông cải xanh hữu cơ',
            'Rau muống hữu cơ',
        ]) . ' ' . fake()->numberBetween(1, 99);

        return [
            'category_id' => Category::inRandomOrder()->value('id') ?? 1,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'description' => fake()->sentence(12),
            'image_path' => null,
            'base_price' => fake()->randomElement([25000, 35000, 45000, 55000, 65000]),
            'unit' => fake()->randomElement(['kg', 'bó', 'hộp']),
            'is_active' => 1,
        ];
    }
}