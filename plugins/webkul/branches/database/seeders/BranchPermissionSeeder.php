<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BranchPermissionSeeder extends Seeder
{
    /**
     * Branch permissions (plan §7.4).
     */
    public const PERMISSIONS = [
        'view_branch',
        'create_branch',
        'edit_branch',
        'delete_branch',
        'view_branch_profile',
        'manage_branch_users',
        'view_all_branches',
        'view_own_branch',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = Role::query()->where('name', 'Admin')->first();

        $guard = $admin?->guard_name ?? 'web';

        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ])
        );

        if ($admin) {
            $admin->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
