<?php

namespace Database\Seeders;

use App\Enums\AiActionType;
use App\Models\AiPromptTemplate;
use App\Models\Category;
use App\Models\SheetSource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The single login account. Credentials come from the environment so the
        // password is never committed; re-running only ever resets the password
        // when OMNITRACK_USER_PASSWORD is explicitly set.
        $email = (string) env('OMNITRACK_USER_EMAIL', 'me@omnitrack.local');
        $password = env('OMNITRACK_USER_PASSWORD');

        $user = User::firstOrNew(['email' => $email]);
        $user->name = (string) env('OMNITRACK_USER_NAME', 'Omnitrack');

        if (! $user->exists || filled($password)) {
            $user->password = Hash::make(filled($password) ? $password : Str::password(16));
        }

        $user->save();

        if (! filled($password) && $user->wasRecentlyCreated) {
            $this->command?->warn(
                'User '.$email.' dibuat dengan password acak. Set OMNITRACK_USER_PASSWORD lalu jalankan db:seed lagi.'
            );
        }

        // Remove any other account: this is a single-user tool, and a leftover
        // account (for example a placeholder) would be an open door.
        User::where('email', '!=', $email)->delete();

        // Starting categories. firstOrCreate means re-running the seeder never
        // overwrites a name you have since edited.
        foreach (['Kuliah', 'Kantor', 'Pribadi'] as $index => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'order_index' => $index]
            );
        }

        // Register the sheet from the PRD so the sync button has a target even
        // before credentials are injected.
        SheetSource::firstOrCreate(
            ['spreadsheet_id' => (string) config('services.google.spreadsheet_id')],
            [
                'name' => (string) config('services.google.sheet_name', 'Workspace Master Tracker'),
                'sheet_range' => (string) config('services.google.sheet_range', 'Sheet1!A2:H'),
                'is_active' => true,
            ]
        );

        // Quick-prompt presets for the Ctrl + P palette, including the new
        // full-control agent.
        $templates = [
            [
                'title' => 'Kendalikan penuh',
                'prompt_payload' => 'Pindahkan project ini ke kategori Kantor',
                'action_type' => AiActionType::AGENT,
            ],
            [
                'title' => 'Buat task baru dari teks',
                'prompt_payload' => 'Buat task baru dari teks berikut: ',
                'action_type' => AiActionType::PARSE_TASK,
            ],
            [
                'title' => 'Pecah project jadi 3 sub-modul teknis',
                'prompt_payload' => 'Pecah project ini menjadi 3 sub-modul teknis.',
                'action_type' => AiActionType::BREAKDOWN_PROJECT,
            ],
            [
                'title' => 'Urutkan ulang modul',
                'prompt_payload' => 'Urutkan ulang modul project ini, yang paling mendesak di atas.',
                'action_type' => AiActionType::AGENT,
            ],
            [
                'title' => 'Sinkronkan Google Sheets sekarang',
                'prompt_payload' => 'Sinkronkan Google Sheets sekarang.',
                'action_type' => AiActionType::TRIGGER_SYNC,
            ],
            [
                'title' => 'Ringkas kondisi board',
                'prompt_payload' => 'Ringkas kondisi board saat ini dan sebutkan prioritas berikutnya.',
                'action_type' => AiActionType::SUMMARIZE_BOARD,
            ],
        ];

        foreach ($templates as $template) {
            AiPromptTemplate::firstOrCreate(
                ['title' => $template['title']],
                [
                    'prompt_payload' => $template['prompt_payload'],
                    'action_type' => $template['action_type'],
                ]
            );
        }
    }
}
