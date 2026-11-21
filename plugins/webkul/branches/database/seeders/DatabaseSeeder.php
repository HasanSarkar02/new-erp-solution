<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BranchPermissionSeeder::class,
            DemoBranchSeeder::class,
            InternalAccountsSeeder::class,
        ]);
    }
}
