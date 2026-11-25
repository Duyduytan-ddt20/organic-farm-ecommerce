<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('delivery_schedule_id')->nullable()->unique()->constrained('delivery_schedules')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('replacement_claim_id')->nullable()->unique(); // FK gắn sau khi tạo bảng claims
            $table->string('order_code', 50)->unique();
            $table->string('order_type', 20)->default('retail');
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 20);
            $table->string('delivery_address', 500);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'created_at'], 'idx_orders_status_created');
            $table->index('created_at', 'idx_orders_created');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_amounts CHECK (shipping_fee >= 0 AND total_amount >= 0)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_type CHECK (order_type IN ('retail', 'subscription', 'replacement'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('pending', 'confirmed', 'shipping', 'delivered', 'cancelled'))");

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (quantity > 0 AND unit_price >= 0)");

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 10, 2);
            $table->dateTime('allocated_at');
            $table->dateTime('released_at')->nullable();

            $table->unique(['order_item_id', 'batch_id'], 'uk_order_item_batch');
        });

        DB::statement("ALTER TABLE order_details ADD CONSTRAINT chk_order_details_qty CHECK (quantity > 0)");

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->restrictOnDelete()->restrictOnUpdate();
            $table->string('payment_reference', 100)->unique();
            $table->string('provider_transaction_id', 100)->nullable()->unique();
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'created_at'], 'idx_payments_status_created');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_target CHECK ((order_id IS NOT NULL AND subscription_id IS NULL) OR (order_id IS NULL AND subscription_id IS NOT NULL))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cod', 'vnpay_sandbox', 'mock'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN ('pending', 'succeeded', 'failed', 'cancelled'))");

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('reason', 100);
            $table->text('description')->nullable();
            $table->decimal('claimed_quantity', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->string('resolution_type', 20)->nullable();
            $table->text('response_note')->nullable();
            $table->dateTime('submitted_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'created_at'], 'idx_claims_status_created');
        });

        DB::statement("ALTER TABLE claims ADD CONSTRAINT chk_claims_qty CHECK (claimed_quantity > 0)");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT chk_claims_status CHECK (status IN ('pending', 'approved', 'rejected', 'resolved'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT chk_claims_resolution CHECK (resolution_type IS NULL OR resolution_type IN ('refund', 'replacement'))");

        // Hoàn tất vòng tham chiếu orders.replacement_claim_id -> claims.id
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('replacement_claim_id', 'fk_orders_replacement_claim')
                ->references('id')->on('claims')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('claim_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->restrictOnDelete()->restrictOnUpdate();
            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->dateTime('created_at');
        });

        DB::statement("ALTER TABLE claim_attachments ADD CONSTRAINT chk_claim_att_size CHECK (file_size_bytes > 0)");

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('claim_id')->nullable()->unique()->constrained('claims')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('delivery_schedule_id')->nullable()->unique()->constrained('delivery_schedules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('refund_reference', 100)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->string('evidence_path', 255)->nullable();
            $table->text('reason');
            $table->dateTime('refunded_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'created_at'], 'idx_refunds_status_created');
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount CHECK (amount > 0)");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_target CHECK ((claim_id IS NOT NULL AND delivery_schedule_id IS NULL) OR (claim_id IS NULL AND delivery_schedule_id IS NOT NULL))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_status CHECK (status IN ('pending', 'succeeded', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('claim_attachments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('fk_orders_replacement_claim');
        });
        Schema::dropIfExists('claims');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};