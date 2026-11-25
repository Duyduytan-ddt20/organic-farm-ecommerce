<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class MysqlCheck
{
    /**
     * Ràng buộc CHECK dùng cú pháp ALTER TABLE của MySQL.
     * SQLite (PHPUnit) bỏ qua câu lệnh này để migration vẫn chạy được.
     */
    public static function add(string $sql): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement($sql);
        }
    }
}
