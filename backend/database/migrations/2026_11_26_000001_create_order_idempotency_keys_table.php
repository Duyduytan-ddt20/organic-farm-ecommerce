<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key_hash'], 'uq_order_idempotency_user_key');
            $table->unique('order_id', 'uq_order_idempotency_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_idempotency_keys');
    }
};
