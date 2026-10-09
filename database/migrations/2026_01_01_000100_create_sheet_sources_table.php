<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('spreadsheet_id');
            $table->string('sheet_range')->default('Sheet1!A2:H');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_sources');
    }
};
