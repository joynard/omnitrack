<?php

$googleCredentials = env('GOOGLE_APPLICATION_CREDENTIALS');

return [
    'google' => [
        // The PRD expects the service-account JSON to be injected manually at
        // storage/app/google/credentials.json. GoogleSheetService degrades
        // gracefully (skips ingestion) when this file is absent.
        //
        // The blank check matters: .env ships this key as an empty string, and
        // env() returns "" rather than falling through to the default, which
        // would leave the path sanitized away and break the "credentials
        // present?" probe.
        'credentials_path' => filled($googleCredentials)
            ? (string) $googleCredentials
            : storage_path('app/google/credentials.json'),

        // Defaults come from the PRD so a fresh deploy ingests immediately.
        'spreadsheet_id' => env('GOOGLE_SHEET_ID', '1lgivxc5ZnctCyX9swahxWjZmiq2tl7JSOEHvJ2Dxktw'),
        'sheet_range' => env('GOOGLE_SHEET_RANGE', 'Sheet1!A2:H'),
        'sheet_name' => env('GOOGLE_SHEET_NAME', 'Workspace Master Tracker'),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'timeout' => (int) env('DEEPSEEK_TIMEOUT', 60),
    ],

    'telegram' => [
        // Token from @BotFather. Empty disables the whole module: the poller
        // exits cleanly instead of crash-looping, so a fresh clone needs no bot
        // to boot.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),

        // Chat ids allowed to drive the workspace. This module can create and
        // DELETE projects, modules and tasks, so the check fails closed: an
        // empty list serves nobody rather than everybody. A chat id is learned
        // by sending /start to the bot while this list is still empty.
        'allowed_chat_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ALLOWED_CHAT_IDS', '')),
        ), fn (string $id) => $id !== '')),

        'base_url' => env('TELEGRAM_BASE_URL', 'https://api.telegram.org'),

        // Seconds Telegram holds an idle long poll open. Keep the HTTP request
        // timeout comfortably above it or every empty poll looks like a failure.
        'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 25),
        'request_timeout' => (int) env('TELEGRAM_REQUEST_TIMEOUT', 45),

        // Telegram hard-rejects any body above 4096 characters. Staying under
        // it leaves room for the split marker and any entity overhead.
        'max_message_length' => 4000,
    ],

    'harness' => [
        'token' => env('HARNESS_SECRET_TOKEN'),
    ],
];
