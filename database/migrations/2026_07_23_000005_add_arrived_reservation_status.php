<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE reservations MODIFY status ENUM('pending','confirmed','arrived','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE reservations SET status = 'confirmed' WHERE status = 'arrived'");
        DB::statement("ALTER TABLE reservations MODIFY status ENUM('pending','confirmed','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'pending'");
    }
};
