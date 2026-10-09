<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('prompt_payload');
            $table->string('action_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_templates');
    }
};
