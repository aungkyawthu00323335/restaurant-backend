<?php

namespace Database\Seeders;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        AppServiceProvider::cleanPublicStorage();

        $this->call([
            SuperAdminSeeder::class,
            PrinterSeeder::class,
        ]);
    }
}
