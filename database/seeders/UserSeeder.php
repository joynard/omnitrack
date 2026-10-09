<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The single login account, and nothing else.
 *
 * Credentials come from the environment so the password is never committed, and
 * re-running only resets the password when OMNITRACK_USER_PASSWORD is non-empty
 * -- which makes this safe to run repeatedly to change a forgotten password.
 *
 *   php artisan db:seed --class=UserSeeder
 *
 * Kept separate from DatabaseSeeder so that seeding the login account does not
 * also create categories, a SheetSource row and the palette presets. Those are
 * optional fixtures; the account is not, because without it nothing can log in.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('OMNITRACK_USER_EMAIL', 'me@omnitrack.local'));
        $password = env('OMNITRACK_USER_PASSWORD');

        $user = User::firstOrNew(['email' => $email]);
        $user->name = trim((string) env('OMNITRACK_USER_NAME', 'Omnitrack'));

        if (! $user->exists || filled($password)) {
            $user->password = Hash::make(filled($password) ? $password : Str::password(16));
        }

        $user->save();

        if (! filled($password) && $user->wasRecentlyCreated) {
            $this->command?->warn(
                'User '.$email.' dibuat dengan password acak. Set OMNITRACK_USER_PASSWORD lalu jalankan UserSeeder lagi.'
            );
        }

        // Remove any other account: this is a single-user tool, and a leftover
        // account (for example a placeholder) would be an open door.
        User::where('email', '!=', $email)->delete();

        $this->command?->info('Login account ready: '.$email);
    }
}
