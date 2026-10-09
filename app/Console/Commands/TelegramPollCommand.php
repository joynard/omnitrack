<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use App\Services\TelegramBot;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-poll Telegram and execute incoming workspace instructions.
 *
 * Long polling rather than a webhook is deliberate: it needs no public URL, no
 * tunnel and no inbound port, so the bot works from a laptop behind NAT while
 * the Docker stack is up -- and stops the moment that stack does.
 */
class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll
                            {--once : Process whatever is pending, then exit instead of looping.}
                            {--timeout= : Long-poll timeout in seconds (default: services.telegram.poll_timeout).}';

    protected $description = 'Long-poll Telegram and run incoming instructions through the Omnitrack agent.';

    public function handle(TelegramService $telegram, TelegramBot $bot): int
    {
        if (! $telegram->configured()) {
            $this->warn('TELEGRAM_BOT_TOKEN belum diisi -- modul Telegram nonaktif.');

            // Exit 0 on purpose. Supervisord treats exit code 0 as an expected
            // stop, so a stack with no bot configured does not restart-loop.
            // Fill in the token and restart the container to enable it.
            return self::SUCCESS;
        }

        $timeout = (int) ($this->option('timeout') ?: config('services.telegram.poll_timeout', 25));
        $once = (bool) $this->option('once');
        $allowed = $telegram->allowedChatIds();

        $this->info(sprintf(
            'Telegram poller jalan (long poll %ds, allow-list: %s).',
            $timeout,
            $allowed === [] ? 'KOSONG -- mode onboarding' : implode(', ', $allowed),
        ));

        if ($allowed === []) {
            $this->warn('TELEGRAM_ALLOWED_CHAT_IDS kosong. Kirim /start ke bot untuk melihat chat_id, lalu isi variabel itu.');
        }

        $offset = TelegramMessage::nextOffset();
        $backoff = 1;
        $running = true;

        // Supervisor stops this program with SIGTERM. Without a handler a 25 s
        // long poll would be cut mid-flight; with one, the in-flight request
        // finishes and the loop exits. No work is lost either way: the offset
        // only advances after an update is stored, and the unique index on
        // update_id makes the resulting redelivery a no-op.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running): void {
                $running = false;
            });
        }

        while ($running) {
            try {
                $updates = $telegram->getUpdates($offset, $timeout);
                $backoff = 1;
            } catch (Throwable $e) {
                $this->error('Poll gagal: '.$e->getMessage());
                $this->line('Kalau statusnya 409: ada poller lain yang jalan, atau webhook masih terpasang (lihat: php artisan telegram:status).');

                if ($once) {
                    return self::FAILURE;
                }

                sleep($backoff);
                $backoff = min($backoff * 2, 30);

                continue;
            }

            foreach ($updates as $update) {
                $this->consume($bot, $update);

                if (is_numeric($update['update_id'] ?? null)) {
                    $offset = max($offset, (int) $update['update_id'] + 1);
                }
            }

            if ($once) {
                return self::SUCCESS;
            }
        }

        $this->info('Telegram poller berhenti.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function consume(TelegramBot $bot, array $update): void
    {
        $record = $bot->handle($update);

        // null means the update was already processed; a redelivery, not an error.
        if ($record === null) {
            return;
        }

        $this->line(sprintf(
            '[%s] chat %s: %s',
            $record->status->value,
            (string) ($record->chat_id ?? '-'),
            mb_strimwidth((string) ($record->text ?? '(tanpa teks)'), 0, 90, '...'),
        ));

        if ($record->error !== null) {
            $this->error('  '.$record->error);
        }
    }
}
