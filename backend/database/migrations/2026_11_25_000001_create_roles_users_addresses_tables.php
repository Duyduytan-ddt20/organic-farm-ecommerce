<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 50)->unique();
            $table->string('description', 255)->nullable();
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT chk_roles_name CHECK (role_name IN ('Customer', 'Staff', 'Sales', 'Admin'))");

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->string('full_name', 100);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_active CHECK (is_active IN (0, 1))");

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 20);
            $table->string('address_line', 500);
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        DB::statement("ALTER TABLE addresses ADD CONSTRAINT chk_addresses_default CHECK (is_default IN (0, 1))");
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};