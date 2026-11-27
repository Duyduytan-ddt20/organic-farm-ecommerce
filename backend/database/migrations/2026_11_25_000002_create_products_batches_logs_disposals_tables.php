<?php

use App\Support\MysqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        MysqlCheck::add('ALTER TABLE categories ADD CONSTRAINT chk_categories_active CHECK (is_active IN (0, 1))');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->decimal('base_price', 12, 2);
            $table->string('unit', 20);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        MysqlCheck::add('ALTER TABLE products ADD CONSTRAINT chk_products_price CHECK (base_price >= 0)');
        MysqlCheck::add('ALTER TABLE products ADD CONSTRAINT chk_products_active CHECK (is_active IN (0, 1))');

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->string('batch_code', 50)->unique();
            $table->string('growing_location', 255);
            $table->date('planted_at')->nullable();
            $table->date('harvested_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->decimal('received_quantity', 10, 2)->default(0);
            $table->decimal('available_quantity', 10, 2)->default(0);
            $table->string('status', 20)->default('growing');
            $table->string('qr_token', 64)->nullable()->unique();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            // 2 chỉ mục ưu tiên từ mục 6 báo cáo Tuần 3
            $table->index(['product_id', 'status', 'expires_at'], 'idx_batches_fefo');
            $table->index(['status', 'expires_at'], 'idx_batches_expiry_alert');
        });

        MysqlCheck::add('ALTER TABLE batches ADD CONSTRAINT chk_batches_qty CHECK (received_quantity >= 0 AND available_quantity >= 0 AND available_quantity <= received_quantity)');
        MysqlCheck::add("ALTER TABLE batches ADD CONSTRAINT chk_batches_status CHECK (status IN ('growing', 'available', 'blocked', 'closed'))");

        Schema::create('farming_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('activity', 100);
            $table->text('description')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->dateTime('performed_at');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['batch_id', 'performed_at'], 'idx_farming_logs_batch_time');
        });

        Schema::create('stock_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 10, 2);
            $table->string('reason', 255);
            $table->text('note')->nullable();
            $table->string('image_path', 255)->nullable(); // Bổ sung từ Tuần 4
            $table->dateTime('disposed_at');
            $table->dateTime('created_at');

            $table->index(['batch_id', 'disposed_at'], 'idx_stock_disposals_batch_time');
        });

        MysqlCheck::add('ALTER TABLE stock_disposals ADD CONSTRAINT chk_stock_disposals_qty CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_disposals');
        Schema::dropIfExists('farming_logs');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
