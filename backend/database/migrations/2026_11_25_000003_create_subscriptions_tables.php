<?php

use App\Support\MysqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        MysqlCheck::add('ALTER TABLE subscription_plans ADD CONSTRAINT chk_sub_plans_duration CHECK (duration_days > 0)');
        MysqlCheck::add('ALTER TABLE subscription_plans ADD CONSTRAINT chk_sub_plans_active CHECK (is_active IN (0, 1))');

        Schema::create('subscription_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity_per_delivery', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['subscription_plan_id', 'product_id'], 'uk_sub_plan_product');
        });

        MysqlCheck::add('ALTER TABLE subscription_plan_items ADD CONSTRAINT chk_sub_plan_items_qty CHECK (quantity_per_delivery > 0 AND unit_price >= 0)');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete()->restrictOnUpdate();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 20);
            $table->string('delivery_address', 500);
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 20)->default('pending_payment');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['status', 'created_at'], 'idx_subscriptions_status_created');
        });

        MysqlCheck::add('ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_dates CHECK (ends_on >= starts_on AND total_amount >= 0)');
        MysqlCheck::add("ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_status CHECK (status IN ('pending_payment', 'active', 'paused', 'completed', 'cancelled'))");

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity_per_delivery', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['subscription_id', 'product_id'], 'uk_sub_item_product');
        });

        MysqlCheck::add('ALTER TABLE subscription_items ADD CONSTRAINT chk_sub_items_qty CHECK (quantity_per_delivery > 0 AND unit_price >= 0)');

        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete()->restrictOnUpdate();
            $table->date('delivery_date');
            $table->dateTime('generate_at');
            $table->decimal('prepaid_amount', 12, 2);
            $table->string('status', 20)->default('scheduled');
            $table->text('resolution_note')->nullable();
            $table->dateTime('customer_agreed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['subscription_id', 'delivery_date'], 'uk_sub_delivery_date');
            $table->index(['status', 'generate_at'], 'idx_schedules_status_gen');
            $table->index(['status', 'delivery_date'], 'idx_schedules_status_date');
        });

        MysqlCheck::add('ALTER TABLE delivery_schedules ADD CONSTRAINT chk_schedules_amount CHECK (prepaid_amount >= 0)');
        MysqlCheck::add("ALTER TABLE delivery_schedules ADD CONSTRAINT chk_schedules_status CHECK (status IN ('scheduled', 'generated', 'shortage', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedules');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plan_items');
        Schema::dropIfExists('subscription_plans');
    }
};
