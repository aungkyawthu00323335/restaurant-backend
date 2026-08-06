<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

try {
    $connection = DB::getDefaultConnection();
    echo "Default Connection: $connection\n";
    
    $database = DB::connection()->getDatabaseName();
    echo "Database Name: $database\n";

    if (Schema::hasTable('tables')) {
        echo "Table 'tables' exists.\n";
        $columns = Schema::getColumnListing('tables');
        echo "Columns in 'tables':\n";
        print_r($columns);
    } else {
        echo "Table 'tables' does NOT exist!\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
