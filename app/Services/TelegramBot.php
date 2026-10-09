<?php

namespace App\Services;

use App\Enums\AiActionType;
use App\Enums\TelegramMessageStatus;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one Telegram update into a workspace instruction.
 *
 * Nothing about the workspace is reimplemented here: an authorised natural
 * language message runs through the same AiOrchestrator the Ctrl+P palette uses,
 * so the bot inherits all 14 workspace tools and their validation for free.
 * This class only decides *whether* to act and *which* orchestrator action fits.
 */
class TelegramBot
{
    /**
     * Openers that mark a message as a question, not an order.
     *
     * The full-control agent runs with `tool_choice: required`, so it ALWAYS
     * returns a mutation. Sending "apa progres project X?" straight to it would
     * risk editing the workspace to answer a read-only question. Matching on the
     * first word is a cheap, deterministic gate that needs no second classifier
     * call -- questions fall through to the read-only board summary instead.
     */
    private const READ_ONLY_PREFIXES = [
        'apa', 'apakah', 'gimana', 'bagaimana', 'berapa', 'kapan', 'mana', 'dimana', 'di mana',
        'siapa', 'kenapa', 'mengapa', 'ringkas', 'ringkasan', 'rangkum', 'rekap',
        'status', 'list', 'daftar', 'tampilkan', 'tunjukkan', 'lihat', 'cek',
        'show', 'summarize', 'summarise', 'summary', 'report', 'what', 'how', 'when', 'which',
    ];

    /** Openers that mean "pull the Google Sheet in again". */
    private const SYNC_PREFIXES = ['sinkron', 'sinkronkan', 'sync', 'tarik data', 'refresh'];

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly AiOrchestrator $orchestrator,
    ) {}

    /**
     * Handle one raw update: record it, decide the reply, send it.
     *
     * Returns null when the update was already seen or carries no update id.
     *
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): ?TelegramMessage
    {
        $updateId = $update['update_id'] ?? null;

        if (! is_numeric($updateId)) {
            return null;
        }

        $updateId = (int) $updateId;

        // getUpdates is at-least-once, and the offset only advances after an
        // update is stored, so a redelivery after an interrupted poll is normal.
        if (TelegramMessage::query()->where('update_id', $updateId)->exists()) {
            return null;
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;
        $text = trim((string) ($message['text'] ?? ''));

        $record = TelegramMessage::create([
            'update_id' => $updateId,
            'chat_id' => $chatId,
            'telegram_user_id' => isset($message['from']['id']) ? (string) $message['from']['id'] : null,
            'telegram_username' => is_string($message['from']['username'] ?? null) ? $message['from']['username'] : null,
            'text' => $text === '' ? null : $text,
            'status' => TelegramMessageStatus::IGNORED,
        ]);

        try {
            $outcome = $this->respond($chatId, $text);

            if ($outcome['reply'] !== null && $chatId !== null) {
                $this->telegram->sendMessage($chatId, $outcome['reply']);
            }

            $record->forceFill([
                'status' => $outcome['status'],
                'reply' => $outcome['reply'],
            ])->save();
        } catch (Throwable $e) {
            Log::error('Telegram update handling failed.', [
                'update_id' => $updateId,
                'exception' => $e->getMessage(),
            ]);

            $record->forceFill([
                'status' => TelegramMessageStatus::FAILED,
                'error' => $e->getMessage(),
            ])->save();
        }

        return $record;
    }

    /**
     * @return array{status: TelegramMessageStatus, reply: string|null}
     */
    private function respond(?string $chatId, string $text): array
    {
        if ($chatId === null) {
            return ['status' => TelegramMessageStatus::IGNORED, 'reply' => null];
        }

        // Onboarding. With no allow-list there is no other way for the owner to
        // learn their own chat id, so answer exactly that and nothing else.
        // Once the list is filled in this branch is dead and unknown chats are
        // ignored silently -- an unauthorised sender learns nothing.
        if ($this->telegram->allowedChatIds() === []) {
            return [
                'status' => TelegramMessageStatus::IGNORED,
                'reply' => "Bot Omnitrack belum diaktifkan.\n\n"
                    ."chat_id kamu: {$chatId}\n\n"
                    .'Tambahkan angka itu ke TELEGRAM_ALLOWED_CHAT_IDS, lalu restart container.',
            ];
        }

        if (! $this->telegram->isAllowed($chatId)) {
            Log::warning('Telegram message from an unauthorised chat was ignored.', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            return ['status' => TelegramMessageStatus::IGNORED, 'reply' => null];
        }

        if ($text === '') {
            return [
                'status' => TelegramMessageStatus::IGNORED,
                'reply' => 'Omnitrack hanya memproses pesan teks.',
            ];
        }

        $command = $this->command($text);

        if ($command !== null) {
            return $this->respondToCommand($command, $chatId);
        }

        // The agent answer takes seconds; without this the phone shows nothing
        // happening in the meantime.
        $this->telegram->sendChatAction($chatId);

        $result = $this->orchestrator->run($this->actionFor($text), $text);

        return [
            'status' => ($result['status'] ?? 'error') === 'error'
                ? TelegramMessageStatus::FAILED
                : TelegramMessageStatus::HANDLED,
            'reply' => (string) ($result['message'] ?? 'Omnitrack tidak mengembalikan jawaban.'),
        ];
    }

    /**
     * Only /start, /help and /id exist. They are onboarding, not a command
     * surface: everything else is meant to be said in plain language.
     *
     * @return array{status: TelegramMessageStatus, reply: string|null}
     */
    private function respondToCommand(string $command, string $chatId): array
    {
        return match ($command) {
            'start', 'help' => [
                'status' => TelegramMessageStatus::HANDLED,
                'reply' => $this->helpText($chatId),
            ],
            'id' => [
                'status' => TelegramMessageStatus::HANDLED,
                'reply' => 'chat_id kamu: '.$chatId,
            ],
            default => [
                'status' => TelegramMessageStatus::HANDLED,
                'reply' => 'Perintah /'.$command.' tidak dikenal.'."\n\n".$this->helpText($chatId),
            ],
        };
    }

    /**
     * Pick the orchestrator action for a plain-language message.
     *
     * The order matters: an explicit "sinkronkan" wins over the read-only
     * prefixes, because "sinkronkan sheet" is an order even though it starts
     * with a verb that could look like a query.
     */
    private function actionFor(string $text): AiActionType
    {
        $normalised = mb_strtolower(trim($text));

        if ($this->startsWithAny($normalised, self::SYNC_PREFIXES)) {
            return AiActionType::TRIGGER_SYNC;
        }

        if (str_ends_with(trim($text), '?')) {
            return AiActionType::SUMMARIZE_BOARD;
        }

        if ($this->startsWithAny($normalised, self::READ_ONLY_PREFIXES)) {
            return AiActionType::SUMMARIZE_BOARD;
        }

        return AiActionType::AGENT;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accepts "/start" and the group form "/start@OmnitrackBot".
     */
    private function command(string $text): ?string
    {
        if (! str_starts_with($text, '/')) {
            return null;
        }

        $first = preg_split('/\s+/', $text)[0] ?: '';
        $name = mb_strtolower(ltrim(explode('@', $first)[0], '/'));

        return $name === '' ? null : $name;
    }

    private function helpText(string $chatId): string
    {
        return <<<TEXT
        Omnitrack siap. Kirim instruksi bebas — sama seperti Ctrl+P di web.

        Contoh:
        • Buat project "Vendor Migration Q4" di kategori Kantor
        • Tambah modul "Draft SLA" dan "Audit vendor" ke project itu
        • Tandai task "Follow up vendor" selesai
        • Sinkronkan Google Sheets
        • Apa progres project Vendor Migration Q4?

        Catatan:
        • Perintah dijalankan langsung tanpa konfirmasi, jadi sebutkan nama
          dengan jelas.
        • Pertanyaan (diakhiri "?") dijawab sebagai ringkasan board dan tidak
          pernah mengubah data.

        chat_id: {$chatId}
        TEXT;
    }
}
