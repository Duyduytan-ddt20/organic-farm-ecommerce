<?php

use App\Support\MysqlCheck;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MysqlCheck::add('ALTER TABLE payments DROP CHECK chk_payments_method');
        MysqlCheck::add("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cod', 'vnpay_sandbox', 'momo_sandbox', 'mock'))");
    }

    public function down(): void
    {
        MysqlCheck::add('ALTER TABLE payments DROP CHECK chk_payments_method');
        MysqlCheck::add("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cod', 'vnpay_sandbox', 'mock'))");
    }
};
