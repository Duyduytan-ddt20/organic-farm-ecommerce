<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: Bảng 'orders' đã được tạo bởi migration
// 2026_11_25_000004_create_orders_payments_claims_refunds_tables.php (phiên bản đầy đủ hơn).
// Migration này được giữ lại để tương thích với git history của nhánh khác.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->text('shipping_address');
            $table->string('status', 30)->default('pending')->index();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
