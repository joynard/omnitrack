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

    'harness' => [
        'token' => env('HARNESS_SECRET_TOKEN'),
    ],
];
