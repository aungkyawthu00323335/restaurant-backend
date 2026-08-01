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

        // Demo catalog data is useful for local QA, but must never be inserted
        // into a production database by a normal deployment seed command.
        if (app()->environment(['local', 'testing'])) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
