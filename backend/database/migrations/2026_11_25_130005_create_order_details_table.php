<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: Bảng 'order_details' đã được tạo bởi migration
// 2026_11_25_000004_create_orders_payments_claims_refunds_tables.php (phiên bản đầy đủ hơn).
// Migration này được giữ lại để tương thích với git history của nhánh khác.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_details')) {
            return;
        }

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->index(['order_item_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_details');
    }
};
