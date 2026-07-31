<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('ip_address', 45);
            $table->unsignedInteger('port')->default(9100);
            $table->string('paper_size', 10)->default('80mm');
            $table->unsignedInteger('copies')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ip_address', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
