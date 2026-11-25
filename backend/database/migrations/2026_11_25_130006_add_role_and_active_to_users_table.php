<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: Cột 'role_id' và 'is_active' đã được thêm vào bảng 'users' bởi migration
// 2026_11_25_000001_create_roles_users_addresses_tables.php (phiên bản đầy đủ hơn).
// Migration này được giữ lại để tương thích với git history của nhánh khác.
return new class extends Migration
{
    public function up(): void
    {
        // Các cột đã tồn tại trong migration gộp đầy đủ; không cần thêm lại.
        if (Schema::hasColumn('users', 'is_active')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role_name', 40)->default('Customer')->index();
            $table->boolean('is_active')->default(true)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'role_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_name']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role_name', 'is_active']);
        });
    }
};
