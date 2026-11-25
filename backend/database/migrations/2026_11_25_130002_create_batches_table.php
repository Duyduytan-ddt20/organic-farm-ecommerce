<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: Bảng 'batches' đã được tạo bởi migration
// 2026_11_25_000002_create_products_batches_logs_disposals_tables.php (phiên bản đầy đủ hơn).
// Migration này được giữ lại để tương thích với git history của nhánh khác.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('batches')) {
            return;
        }

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_code')->unique();
            $table->date('expiration_date')->index();
            $table->decimal('quantity_initial', 12, 3);
            $table->decimal('quantity_available', 12, 3)->default(0);
            $table->timestamps();
            $table->index(['product_id', 'expiration_date', 'quantity_available'], 'batches_fefo_stock_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
