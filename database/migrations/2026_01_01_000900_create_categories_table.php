<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->default('neutral');
            $table->integer('order_index')->default(0);
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            // Nullable + nullOnDelete: deleting a category must never delete the
            // projects filed under it, they simply become uncategorised.
            $table->foreignUlid('category_id')
                ->nullable()
                ->after('id')
                ->constrained('categories')
                ->nullOnDelete();

            // Manual ordering within a category.
            $table->integer('order_index')->default(0)->after('row_hash');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('order_index');
        });

        Schema::dropIfExists('categories');
    }
};
