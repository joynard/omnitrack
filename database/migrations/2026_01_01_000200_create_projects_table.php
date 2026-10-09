<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Nullable + nullOnDelete so a manually created project survives
            // removal of the spreadsheet registration it came from.
            $table->foreignUlid('sheet_source_id')
                ->nullable()
                ->constrained('sheet_sources')
                ->nullOnDelete();

            $table->integer('sheet_row_index')->nullable();

            $table->string('name');
            $table->string('my_role')->nullable();
            $table->string('department')->nullable();
            $table->string('target_user')->nullable();
            $table->string('project_owner')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('doc_link')->nullable();
            $table->string('row_hash')->nullable();
            $table->timestamps();

            // Reference key for Project::upsert() during sheet ingestion.
            $table->unique(['sheet_source_id', 'sheet_row_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
