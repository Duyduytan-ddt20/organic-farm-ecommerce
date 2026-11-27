<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        // 1. Roles
        DB::table('roles')->insert([
            ['id' => 1, 'role_name' => 'Customer', 'description' => 'Khách hàng mua sắm'],
            ['id' => 2, 'role_name' => 'Staff', 'description' => 'Nhân viên nông trại'],
            ['id' => 3, 'role_name' => 'Sales', 'description' => 'Nhân viên bán hàng và CSKH'],
            ['id' => 4, 'role_name' => 'Admin', 'description' => 'Quản trị viên hệ thống'],
        ]);

        // 2. Users (Mật khẩu chung: 12345678)
        DB::table('users')->insert([
            ['id' => 1, 'role_id' => 4, 'full_name' => 'Trần Duy Tân (Admin)', 'email' => 'admin@organicfarm.vn', 'phone' => '0901000001', 'password' => Hash::make('12345678'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'role_id' => 2, 'full_name' => 'Nhân viên An (Staff)', 'email' => 'staff@organicfarm.vn', 'phone' => '0901000002', 'password' => Hash::make('12345678'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'role_id' => 3, 'full_name' => 'Nguyễn Lâm Nhật Tiến (Sales)', 'email' => 'sales@organicfarm.vn', 'phone' => '0901000003', 'password' => Hash::make('12345678'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'role_id' => 1, 'full_name' => 'Khách hàng Mẫu', 'email' => 'customer@organicfarm.vn', 'phone' => '0901000004', 'password' => Hash::make('12345678'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // 3. Categories & Products
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Rau ăn lá', 'slug' => 'rau-an-la', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Củ quả tươi', 'slug' => 'cu-qua-tuoi', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('products')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'Xà lách tươi', 'slug' => 'xa-lach-tuoi', 'description' => 'Xà lách thủy canh/hữu cơ tại vườn.', 'base_price' => 45000, 'unit' => 'kg', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'category_id' => 2, 'name' => 'Cà chua hữu cơ', 'slug' => 'ca-chua-huu-co', 'description' => 'Cà chua chín cây đạt chuẩn VietGAP.', 'base_price' => 38000, 'unit' => 'kg', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // 4. Batches cố định để test FEFO và giao diện quản lý.
        DB::table('batches')->insert([
            ['id' => 1, 'product_id' => 2, 'batch_code' => 'CC-EXPIRED', 'growing_location' => 'Nhà màng 1', 'planted_at' => '2026-09-20', 'harvested_at' => '2026-11-15', 'expires_at' => $now->copy()->subHours(5), 'received_quantity' => 20, 'available_quantity' => 10, 'status' => 'available', 'qr_token' => Str::random(32), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'product_id' => 2, 'batch_code' => 'CC-EMPTY', 'growing_location' => 'Nhà màng 1', 'planted_at' => '2026-09-22', 'harvested_at' => '2026-11-18', 'expires_at' => $now->copy()->addHours(20), 'received_quantity' => 15, 'available_quantity' => 0, 'status' => 'available', 'qr_token' => Str::random(32), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'product_id' => 2, 'batch_code' => 'CC-A01', 'growing_location' => 'Nhà màng 2', 'planted_at' => '2026-09-25', 'harvested_at' => '2026-11-22', 'expires_at' => $now->copy()->addHours(30), 'received_quantity' => 15, 'available_quantity' => 3, 'status' => 'available', 'qr_token' => Str::random(32), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'product_id' => 2, 'batch_code' => 'CC-B02', 'growing_location' => 'Nhà màng 3', 'planted_at' => '2026-09-28', 'harvested_at' => '2026-11-24', 'expires_at' => $now->copy()->addDays(5), 'received_quantity' => 30, 'available_quantity' => 25, 'status' => 'available', 'qr_token' => Str::random(32), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'product_id' => 1, 'batch_code' => 'A12', 'growing_location' => 'Khu A - Luống 12', 'planted_at' => '2026-10-05', 'harvested_at' => '2026-11-20', 'expires_at' => $now->copy()->addDays(3), 'received_quantity' => 50, 'available_quantity' => 45, 'status' => 'available', 'qr_token' => Str::random(32), 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->call(DemoOrderSeeder::class);

        // 5. Generate additional products and stock lots for the demo.
        Product::factory(5)->create()->each(function (Product $product) {
            Batch::factory()->create(['product_id' => $product->id]);
            Batch::factory()->expiringSoon()->create(['product_id' => $product->id]);
            Batch::factory()->outOfStock()->create(['product_id' => $product->id]);
        });
    }
}
