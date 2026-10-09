<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleSheetJob;
use App\Models\Project;
use App\Models\TelegramMessage;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Telegram module is verified entirely through fakes.
 *
 * No test may reach api.telegram.org or the DeepSeek API; the real behaviour
 * being asserted is the routing decision (which orchestrator action a message
 * becomes), the authorisation gate, and the idempotency of a redelivered update.
 */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_poller_exits_cleanly_when_the_bot_token_is_missing(): void
    {
        config()->set('services.telegram.bot_token', '');

        // Exit 0, not 1: supervisord must not restart-loop a stack that simply
        // has no bot configured yet.
        $this->artisan('telegram:poll --once')->assertExitCode(0);
    }

    public function test_natural_language_instruction_is_executed_and_answered(): void
    {
        $this->configureBot();
        $this->fakeTelegram([
            $this->update(1, '12345', 'Buat project "Vendor Migration Q4" di kategori Kantor'),
        ], arguments: ['name' => 'Vendor Migration Q4', 'category_name' => 'Kantor']);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertDatabaseHas('projects', ['name' => 'Vendor Migration Q4']);
        $this->assertDatabaseHas('categories', ['name' => 'Kantor']);
        $this->assertDatabaseHas('telegram_messages', [
            'update_id' => 1,
            'chat_id' => '12345',
            'status' => 'handled',
        ]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '12345');
    }

    public function test_unauthorised_chat_is_ignored_and_never_reaches_the_agent(): void
    {
        $this->configureBot(['99999']);
        $this->fakeTelegram([
            $this->update(2, '12345', 'Hapus semua project'),
        ]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseHas('telegram_messages', ['update_id' => 2, 'status' => 'ignored']);

        // No reply at all: an unauthorised sender learns nothing about the bot.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sendMessage'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'deepseek'));
    }

    public function test_empty_allow_list_answers_with_the_chat_id_for_onboarding(): void
    {
        $this->configureBot([]);
        $this->fakeTelegram([$this->update(3, '555', '/start')]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '555'));

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_question_is_answered_read_only_and_cannot_mutate(): void
    {
        $this->configureBot();
        Project::factory()->create(['name' => 'Existing Project']);

        // DeepSeek is faked to return a create_project tool call. A question must
        // never get that far: the summariser uses no tools at all.
        $this->fakeTelegram([
            $this->update(4, '12345', 'Apa progres project Existing Project?'),
        ], arguments: ['name' => 'Harusnya Tidak Dibuat']);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseMissing('projects', ['name' => 'Harusnya Tidak Dibuat']);
        $this->assertDatabaseHas('telegram_messages', ['update_id' => 4, 'status' => 'handled']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'deepseek')) {
                return false;
            }

            return ! isset($request->data()['tools']);
        });
    }

    public function test_a_sync_instruction_queues_the_google_sheet_job(): void
    {
        Queue::fake();

        $this->configureBot();
        $this->fakeTelegram([$this->update(5, '12345', 'Sinkronkan Google Sheets sekarang')]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        Queue::assertPushed(SyncGoogleSheetJob::class);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'deepseek'));
    }

    public function test_a_redelivered_update_is_not_executed_twice(): void
    {
        $this->configureBot();
        $this->fakeTelegram([
            $this->update(7, '12345', 'Buat project "Sekali Saja"'),
        ], arguments: ['name' => 'Sekali Saja']);

        $this->artisan('telegram:poll --once')->assertExitCode(0);
        $this->assertDatabaseCount('projects', 1);

        // getUpdates is at-least-once. The fake re-serves update 7, so the second
        // poll must recognise the id it already stored.
        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('telegram_messages', 1);
    }

    public function test_next_offset_follows_the_highest_stored_update(): void
    {
        $this->assertSame(0, TelegramMessage::nextOffset());

        $this->fakeTelegram([$this->update(42, '12345', 'Buat project "Offset Test"')], arguments: ['name' => 'Offset Test']);
        $this->configureBot();

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertSame(43, TelegramMessage::nextOffset());
    }

    public function test_long_replies_are_split_below_the_telegram_limit(): void
    {
        config()->set('services.telegram.max_message_length', 40);

        $text = str_repeat("baris yang cukup panjang\n", 12);
        $chunks = app(TelegramService::class)->splitMessage($text);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(40, mb_strlen($chunk));
        }

        // Splitting must not silently drop content.
        $this->assertSame(
            preg_replace('/\s+/', '', $text),
            preg_replace('/\s+/', '', implode('', $chunks)),
        );
    }

    public function test_a_non_text_message_is_answered_without_touching_the_workspace(): void
    {
        $this->configureBot();

        $this->fakeTelegram([[
            'update_id' => 9,
            'message' => [
                'message_id' => 9,
                'from' => ['id' => 12345, 'username' => 'tester'],
                'chat' => ['id' => 12345, 'type' => 'private'],
                'date' => time(),
                'voice' => ['file_id' => 'abc', 'duration' => 3],
            ],
        ]]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseHas('telegram_messages', ['update_id' => 9, 'status' => 'ignored']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'deepseek'));
    }

    // --------------------------------------------------------------- helpers

    /** @param list<string> $chatIds */
    private function configureBot(array $chatIds = ['12345']): void
    {
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.allowed_chat_ids', $chatIds);
        config()->set('services.deepseek.api_key', 'test-deepseek-key');
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  array<string, mixed>  $arguments
     */
    private function fakeTelegram(array $updates, string $tool = 'create_project', array $arguments = []): void
    {
        Http::fake(function (Request $request) use ($updates, $tool, $arguments) {
            $url = $request->url();

            if (str_contains($url, 'api.telegram.org')) {
                if (str_contains($url, '/getUpdates')) {
                    return Http::response(['ok' => true, 'result' => $updates]);
                }

                if (str_contains($url, '/getMe')) {
                    return Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'omnitrack_test_bot']]);
                }

                return Http::response(['ok' => true, 'result' => true]);
            }

            if (str_contains($url, 'deepseek')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => $tool,
                                    'arguments' => json_encode($arguments),
                                ],
                            ]],
                        ],
                    ]],
                ]);
            }

            return Http::response(['ok' => true]);
        });
    }

    /** @return array<string, mixed> */
    private function update(int $updateId, string $chatId, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => (int) $chatId, 'username' => 'tester'],
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }
}
