<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BranchPermissionSeeder extends Seeder
{
    /**
     * Branch + transfer permissions (plan §7.4).
     *
     * The `view_any_*` names are the ones Filament Shield generates
     * for resources (Phase 1's test needed `view_any_branch_branch`
     * for the branch list page to load). The policy methods check the
     * plain names (view_branch, view_transfer, ...); granting both
     * sets to Admin keeps the menu, the list pages and the policies
     * in agreement.
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
        'view_any_branch',
        'view_any_branch_branch',
        'view_transfer',
        'create_transfer',
        'edit_transfer',
        'delete_transfer',
        'confirm_transfer',
        'ship_transfer',
        'receive_transfer',
        'cancel_transfer',
        'return_transfer',
        'view_any_branch_transfer_sale',
        'view_transfer_price_rule',
        'create_transfer_price_rule',
        'edit_transfer_price_rule',
        'delete_transfer_price_rule',
        'view_any_branch_transfer_price_rule',
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
