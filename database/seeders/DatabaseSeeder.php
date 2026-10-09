<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The single login account, and nothing else.
     *
     * This used to also create starting categories, the SheetSource row and the
     * six Ctrl+P palette presets. Those are optional fixtures, and on a local
     * instance a partial seed is worse than none: `migrate:fresh --seed` is the
     * documented reset command, and it would silently wipe real projects and
     * tasks just to recreate presets that can be added by hand.
     *
     * So `db:seed` now means exactly one thing -- make sure a login exists.
     * Everything else is left to the application UI.
     *
     *   php artisan db:seed                     -> login account only
     *   php artisan db:seed --class=UserSeeder  -> the same thing, explicitly
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);
    }
}
