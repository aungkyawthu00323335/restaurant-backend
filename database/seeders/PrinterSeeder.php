<?php

namespace Database\Seeders;

use App\Models\Printer;
use Illuminate\Database\Seeder;

class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        Printer::query()->updateOrCreate(
            ['name' => 'Kitchen Printer'],
            [
                'ip_address' => '127.0.0.1',
                'port' => 9100,
                'paper_size' => '80mm',
                'copies' => 1,
                'is_active' => true,
                'note' => 'Default printer for Food Menu KOT.',
            ]
        );

        Printer::query()->updateOrCreate(
            ['name' => 'Product Printer'],
            [
                'ip_address' => '127.0.0.1',
                'port' => 9101,
                'paper_size' => '80mm',
                'copies' => 1,
                'is_active' => true,
                'note' => 'Dedicated printer for Product KOT tickets.',
            ]
        );
    }
}
