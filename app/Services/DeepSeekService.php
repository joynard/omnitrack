<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client for the DeepSeek chat-completions API.
 *
 * Uses Laravel's native HTTP client, so no extra SDK dependency is required.
 * Supports both plain chat, JSON-constrained chat, and native tool calling.
 */
class DeepSeekService
{
    /**
     * The tool call returned by the most recent chatWithTools() invocation.
     *
     * @var array{name: string, arguments: array<string, mixed>}|null
     */
    private ?array $lastToolCall = null;

    public function configured(): bool
    {
        return filled(config('services.deepseek.api_key'));
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    public function lastToolCall(): ?array
    {
        return $this->lastToolCall;
    }

    /**
     * Send a chat completion and return the assistant message content.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        $response = $this->post(array_filter([
            'model' => $options['model'] ?? config('services.deepseek.model'),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'response_format' => $options['response_format'] ?? null,
            'tools' => $options['tools'] ?? null,
            'tool_choice' => $options['tool_choice'] ?? null,
            'stream' => false,
        ], fn ($value) => $value !== null));

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek returned an empty completion.');
        }

        return $content;
    }

    /**
     * Chat constrained to a JSON object response, decoded into an array.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, array $options = []): array
    {
        $options['response_format'] = ['type' => 'json_object'];

        return $this->decodeJson($this->chat($messages, $options));
    }

    /**
     * Ask the model to choose and fill exactly one tool from the given list.
     *
     * This is what gives the palette full control over modules, ordering and
     * tasks: instead of the app guessing intent from prose, the model returns a
     * structured call that the caller validates and executes.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools  OpenAI-style tool definitions
     * @return array{name: string, arguments: array<string, mixed>}
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $response = $this->post(array_filter([
            'model' => $options['model'] ?? config('services.deepseek.model'),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.1,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'tools' => $tools,
            // Force a tool call so we never have to parse prose.
            'tool_choice' => $options['tool_choice'] ?? 'required',
            'stream' => false,
        ], fn ($value) => $value !== null));

        $toolCall = $response->json('choices.0.message.tool_calls.0');

        if (! is_array($toolCall) || blank($toolCall['function']['name'] ?? null)) {
            // Some models answer in prose despite tool_choice; surface the text
            // so the caller can decide how to recover.
            $content = $response->json('choices.0.message.content');

            throw new RuntimeException(
                'DeepSeek tidak memilih tool apa pun.'
                .(is_string($content) && trim($content) !== '' ? ' Jawaban model: '.trim($content) : '')
            );
        }

        $rawArguments = $toolCall['function']['arguments'] ?? '{}';

        // arguments arrives as a JSON *string* per the OpenAI-compatible spec.
        $arguments = is_string($rawArguments)
            ? json_decode($rawArguments, true)
            : $rawArguments;

        if (! is_array($arguments)) {
            throw new RuntimeException('Argumen tool dari DeepSeek tidak bisa dibaca sebagai JSON.');
        }

        $this->lastToolCall = [
            'name' => (string) $toolCall['function']['name'],
            'arguments' => $arguments,
        ];

        return $this->lastToolCall;
    }

    /**
     * Decode a model response that should be JSON, tolerating code fences and
     * leading prose that models sometimes add despite json_object mode.
     *
     * @return array<string, mixed>
     */
    public function decodeJson(string $content): array
    {
        $clean = trim($content);

        // Strip a ```json ... ``` fence if present.
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-zA-Z]*\s*/', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            // Last resort: extract the outermost {...} block.
            $start = strpos($clean, '{');
            $end = strrpos($clean, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('DeepSeek response was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Http\Client\Response
     */
    private function post(array $payload)
    {
        if (! $this->configured()) {
            throw new RuntimeException('DeepSeek API key is not configured.');
        }

        $response = $this->client()->post('/chat/completions', $payload);

        if ($response->failed()) {
            Log::error('DeepSeek request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'DeepSeek API error ('.$response->status().'): '.$response->json('error.message', 'unknown error')
            );
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.deepseek.base_url'), '/'))
            ->withToken((string) config('services.deepseek.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.deepseek.timeout', 60));
    }
}
