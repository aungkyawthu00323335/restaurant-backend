<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Query 1 (using code):\n";
echo \App\Models\Order::query()->whereHas('table', function ($tQ) {
    $tQ->where('table_no', 'like', '%a%')
       ->orWhere('code', 'like', '%a%');
})->toSql();

echo "\n\nQuery 2 (using name):\n";
echo \App\Models\Order::query()->whereHas('table', function ($tQ) {
    $tQ->where('table_no', 'like', '%a%')
       ->orWhere('name', 'like', '%a%');
})->toSql();
echo "\n";
