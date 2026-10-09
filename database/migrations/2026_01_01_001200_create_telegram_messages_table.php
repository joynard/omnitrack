<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of every Telegram update the poller has seen.
 *
 * It is also the long-poll cursor: the next offset is max(update_id) + 1, so the
 * position survives a container restart without a second store and without a
 * batch being acknowledged that was never actually processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Telegram's own monotonically increasing update id. The unique index
            // is the idempotency guarantee: getUpdates is at-least-once, so a
            // redelivery after a crash must not run the instruction a second time.
            $table->bigInteger('update_id')->unique();

            // Nullable because an update may carry no chat (unsupported type).
            // Stored as a string: group and channel ids are large and negative.
            $table->string('chat_id')->nullable()->index();

            $table->string('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->text('text')->nullable();
            $table->text('reply')->nullable();
            $table->string('status')->default('ignored')->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
