<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin transport for the Telegram Bot API.
 *
 * Uses Laravel's native HTTP client -- the same choice DeepSeekService makes --
 * so the module adds no Composer dependency and therefore no image rebuild.
 */
class TelegramService
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** @return list<string> */
    public function allowedChatIds(): array
    {
        $ids = config('services.telegram.allowed_chat_ids', []);

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    public function isAllowed(string $chatId): bool
    {
        return in_array($chatId, $this->allowedChatIds(), true);
    }

    /**
     * Long poll for new updates. Returns an empty list when nothing is queued.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, ?int $timeout = null): array
    {
        $timeout = $timeout ?? (int) config('services.telegram.poll_timeout', 25);

        $payload = [
            'timeout' => $timeout,
            // Messages only. This also stops Telegram queueing callbacks and
            // channel posts that the bot would never act on.
            'allowed_updates' => ['message'],
        ];

        // offset 0 means "everything Telegram still holds". The parameter is
        // optional, so omit it rather than sending a no-op.
        if ($offset > 0) {
            $payload['offset'] = $offset;
        }

        $result = $this->call('getUpdates', $payload, $timeout + 15)['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function sendMessage(string $chatId, string $text, array $options = []): void
    {
        foreach ($this->splitMessage($text) as $chunk) {
            $this->call('sendMessage', array_merge([
                'chat_id' => $chatId,
                'text' => $chunk,
                // Deliberately no parse_mode: model output regularly contains
                // *, _ and backticks, and one malformed entity makes Telegram
                // reject the entire message with "can't parse entities".
            ], $options));
        }
    }

    /**
     * Best effort: a failed typing indicator must never abort an instruction.
     */
    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 10);
        } catch (Throwable $e) {
            Log::debug('Telegram sendChatAction failed.', ['exception' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public function getMe(): array
    {
        $result = $this->call('getMe')['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /** @return array<string, mixed> */
    public function webhookInfo(): array
    {
        $result = $this->call('getWebhookInfo')['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * getUpdates and a webhook are mutually exclusive: while a webhook is
     * registered, Telegram answers 409 to every long poll.
     */
    public function deleteWebhook(): bool
    {
        return (bool) ($this->call('deleteWebhook', ['drop_pending_updates' => false])['result'] ?? false);
    }

    /**
     * Telegram rejects any body above 4096 characters, so long agent answers are
     * split -- on a newline where one is available.
     *
     * @return list<string>
     */
    public function splitMessage(string $text): array
    {
        $limit = (int) config('services.telegram.max_message_length', 4000);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];

        while (mb_strlen($text) > $limit) {
            $window = mb_substr($text, 0, $limit);
            $break = mb_strrpos($window, "\n");

            // Only break on the newline if it is not near the start of the
            // window; otherwise the chunking could produce tiny fragments.
            $cut = ($break !== false && $break > $limit / 2) ? $break : $limit;

            $chunks[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload = [], ?int $timeout = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN belum diisi.');
        }

        $response = $this->client($timeout)->post('/bot'.$this->token().'/'.$method, $payload);

        if ($response->failed()) {
            // 409 deserves a hint: it means a second poller is running, or a
            // webhook is still registered. Both are invisible from the status
            // code alone, and both are the first thing to check.
            Log::error('Telegram request failed.', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Telegram API error ('.$response->status().'): '.$this->description($response)
            );
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram menolak '.$method.': '.$this->description($response));
        }

        return $body;
    }

    private function description(Response $response): string
    {
        $description = $response->json('description');

        return is_string($description) && $description !== '' ? $description : 'unknown error';
    }

    private function token(): string
    {
        return (string) config('services.telegram.bot_token');
    }

    private function client(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.telegram.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? (int) config('services.telegram.request_timeout', 45));
    }
}
