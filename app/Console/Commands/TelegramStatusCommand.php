<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnosis for the Telegram module: what is configured, which chats have
 * written in, and whether anything is blocking long polling.
 *
 * The chat-id list is the part that matters. A bot cannot be driven until
 * TELEGRAM_ALLOWED_CHAT_IDS contains your id, and the id is otherwise awkward
 * to discover -- this command both shows the ones already seen and tells you
 * how to make the bot reveal yours.
 */
class TelegramStatusCommand extends Command
{
    protected $signature = 'telegram:status
                            {--webhook-off : Unregister any webhook so long polling can work.}';

    protected $description = 'Show Telegram bot configuration, chats seen so far, and webhook state.';

    public function handle(TelegramService $telegram): int
    {
        $configured = $telegram->configured();
        $allowed = $telegram->allowedChatIds();

        $this->table(['Setting', 'Value'], [
            ['TELEGRAM_BOT_TOKEN', $configured ? 'diisi' : 'KOSONG -- modul nonaktif'],
            ['TELEGRAM_ALLOWED_CHAT_IDS', $allowed === [] ? 'KOSONG -- tidak ada yang dilayani' : implode(', ', $allowed)],
            ['Long poll timeout', config('services.telegram.poll_timeout').' s'],
            ['Status webhook', 'belum diperiksa'],
        ]);

        if (! $configured) {
            $this->newLine();
            $this->warn('Isi TELEGRAM_BOT_TOKEN di .env, lalu restart container: ./scripts/dev.ps1 restart');

            return self::SUCCESS;
        }

        $this->inspectRegistration($telegram);
        $this->reportHistory();

        return self::SUCCESS;
    }

    private function inspectRegistration(TelegramService $telegram): void
    {
        try {
            $me = $telegram->getMe();
            $webhook = $telegram->webhookInfo();
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Tidak bisa menghubungi Telegram: '.$e->getMessage());

            return;
        }

        $username = $me['username'] ?? null;
        $url = (string) ($webhook['url'] ?? '');

        $this->newLine();
        $this->line('Bot: '.($username ? '@'.$username : 'tidak diketahui'));

        if ($url !== '') {
            // getUpdates and a webhook are mutually exclusive, so this single
            // fact is the usual reason a poller returns 409 forever.
            $this->newLine();
            $this->warn('Webhook terpasang: '.$url);
            $this->line('Selama webhook aktif, long polling SELALU gagal (409). Hapus dengan:');
            $this->line('  php artisan telegram:status --webhook-off');

            if ((bool) $this->option('webhook-off')) {
                $telegram->deleteWebhook();
                $this->info('Webhook dihapus. Long polling sekarang bisa jalan.');
            }

            return;
        }

        $this->line('Webhook: tidak ada (long polling aman).');
    }

    private function reportHistory(): void
    {
        $total = TelegramMessage::query()->count();
        $nextOffset = TelegramMessage::nextOffset();

        $this->newLine();
        $this->line("Pesan tersimpan: {$total} | offset berikutnya: {$nextOffset}");

        if ($total === 0) {
            $this->newLine();
            $this->line('Belum ada pesan yang masuk. Kirim /start ke bot dari HP;');
            $this->line('bot akan membalas chat_id kamu, lalu masukkan ke TELEGRAM_ALLOWED_CHAT_IDS.');

            return;
        }

        $byStatus = TelegramMessage::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $this->table(
            ['Status', 'Jumlah'],
            collect($byStatus)->map(fn ($count, $status) => [$status, $count])->values()->all(),
        );

        // Distinct chats, newest first: this is what goes into the allow-list.
        $chats = TelegramMessage::query()
            ->whereNotNull('chat_id')
            ->selectRaw('chat_id, max(created_at) as last_seen, count(*) as total')
            ->groupBy('chat_id')
            ->orderByDesc('last_seen')
            ->limit(10)
            ->get();

        $this->table(
            ['chat_id', 'Pesan', 'Terakhir'],
            $chats->map(fn ($row) => [$row->chat_id, $row->total, (string) $row->last_seen])->all(),
        );

        $last = TelegramMessage::query()->orderByDesc('update_id')->first();

        $this->newLine();
        $this->line('Pesan terakhir: ['.$last->status->value.'] '
            .mb_strimwidth((string) ($last->text ?? '(tanpa teks)'), 0, 120, '...'));
    }
}
