<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->date('due_date')->nullable();
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
