<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== categories ===\n";
foreach (App\Models\Category::all() as $cat) {
    echo "ID: {$cat->id}, Name: {$cat->name}, Active: " . ($cat->is_active ? 'Yes' : 'No') . "\n";
}

echo "\n=== product_categories ===\n";
foreach (App\Models\ProductCategory::all() as $cat) {
    echo "ID: {$cat->id}, Name: {$cat->name}, Active: " . ($cat->is_active ? 'Yes' : 'No') . "\n";
}
