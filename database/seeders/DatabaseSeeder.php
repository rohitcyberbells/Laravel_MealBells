<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Hands straight over to DemoSeeder.
     *
     * This class used to build a second, cruder demo world of its own - one
     * tiffin service, one company and three accounts whose password was
     * literally 'password' - with no menus, employees, skips or counts. So
     * `php artisan db:seed`, the command everyone types and every deploy script
     * contains, produced an application with nothing to look at, while the
     * demo worth seeing needed `--class=DemoSeeder` that nobody knew to pass.
     *
     * Two demo worlds is one too many: whichever you got depended on which
     * command you happened to type, and only one of them was maintained.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            /*
             * Say so. Returning in silence means `db:seed --force` on a server
             * prints "Seeding database." and exits 0 having done nothing, which
             * leaves the operator with no way to tell a guard that fired from a
             * seeder that ran. DemoSeeder already says it; this one did not.
             */
            $this->command?->error('DatabaseSeeder only runs in local or testing.');

            return;
        }

        // Silent: DemoSeeder prints its own banner and login table, and the
        // framework's progress line needs console output this may not have.
        $this->call(DemoSeeder::class, true);
    }
}
