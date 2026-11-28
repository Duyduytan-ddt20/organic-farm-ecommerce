<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\FarmingLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class BatchSampleSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::query()->orderBy('id')->get();
        if ($products->isEmpty()) {
            throw new RuntimeException('Không có sản phẩm để tạo lô mẫu. Hãy seed sản phẩm trước.');
        }

        $staff = User::query()
            ->whereHas('role', fn ($query) => $query->where('role_name', 'Staff'))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$staff) {
            throw new RuntimeException('Không có nhân viên đang hoạt động để tạo nhật ký lô mẫu.');
        }

        foreach ($products as $product) {
            foreach ([
                ['A', 2, 10, 3, 'Khu A - Luống '.$product->id],
                ['B', 7, 30, 20, 'Khu B - Luống '.$product->id],
            ] as [$suffix, $expiryDays, $receivedQuantity, $availableQuantity, $location]) {
                Batch::query()->firstOrCreate(
                    ['batch_code' => sprintf('SAMPLE-P%d-%s', $product->id, $suffix)],
                    [
                        'product_id' => $product->id,
                        'growing_location' => $location,
                        'planted_at' => now()->subDays(35)->toDateString(),
                        'harvested_at' => now()->subDays(2)->toDateString(),
                        'expires_at' => now()->addDays($expiryDays),
                        'received_quantity' => $receivedQuantity,
                        'available_quantity' => $availableQuantity,
                        'status' => 'available',
                        'qr_token' => Str::random(32),
                    ],
                );
            }
        }

        Batch::query()
            ->whereDoesntHave('farmingLogs')
            ->orderBy('id')
            ->get()
            ->each(function (Batch $batch) use ($staff): void {
                foreach ([
                    ['Gieo trồng', 'Dữ liệu mẫu: ghi nhận gieo trồng tại '.$batch->growing_location.'.', now()->subDays(35)->setTime(8, 0)],
                    ['Chăm sóc', 'Dữ liệu mẫu: ghi nhận chăm sóc và theo dõi sinh trưởng của lô.', now()->subDays(14)->setTime(8, 0)],
                    ['Thu hoạch', 'Dữ liệu mẫu: ghi nhận thu hoạch và nhập kho lô.', now()->subDays(2)->setTime(8, 0)],
                ] as [$activity, $description, $performedAt]) {
                    FarmingLog::query()->firstOrCreate(
                        ['batch_id' => $batch->id, 'activity' => $activity],
                        [
                            'staff_id' => $staff->id,
                            'description' => $description,
                            'performed_at' => $performedAt,
                        ],
                    );
                }
            });
    }
}
