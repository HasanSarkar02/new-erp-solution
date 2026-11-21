<?php

namespace Webkul\Branch;

use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Policies\BranchPolicy;
use Webkul\Branch\Policies\BranchTransferSalePolicy;
use Webkul\Branch\Services\BranchService;
use Webkul\Branch\Services\BranchTransferPriceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Branch\Services\StockLayerService;
use Webkul\Branch\Services\TransferStockService;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class BranchServiceProvider extends PackageServiceProvider
{
    public static string $name = 'branches';

    public static string $viewNamespace = 'branches';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasMigrations([
                '2026_11_21_100001_create_branches_table',
                '2026_11_21_100002_create_branch_user_table',
                '2026_11_21_100003_add_branch_id_to_inventories_warehouses_table',
                '2026_11_21_100004_add_branch_id_to_partners_partners_table',
                '2026_11_21_100005_create_branch_transfer_sales_table',
                '2026_11_21_100006_create_branch_transfer_sale_lines_table',
                '2026_11_21_100007_create_branch_transfer_price_rules_table',
                '2026_11_21_100008_create_branch_transfer_sale_histories_table',
                '2026_11_21_100009_create_stock_layers_table',
                '2026_11_21_100010_create_stock_layer_consumptions_table',
                '2026_11_21_100011_add_branch_columns_to_inventories_operations_and_moves_tables',
                '2026_11_21_100012_add_receipt_operation_id_to_branch_transfer_sales_table',
            ])
            ->runsMigrations()
            ->hasSeeder('Webkul\\Branch\\Database\\Seeders\\DatabaseSeeder')
            ->hasDependencies([
                'products',
                'inventories',
                'accounts',
                'accounting',
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {
                $command->startWith(function (UninstallCommand $command) {
                    Schema::disableForeignKeyConstraints();

                    try {
                        if (Schema::hasTable('branch_user')) {
                            DB::table('branch_user')->delete();
                        }

                        if (Schema::hasTable('branches')) {
                            DB::table('branches')->delete();
                        }

                        if (Schema::hasTable('inventories_warehouses') && Schema::hasColumn('inventories_warehouses', 'branch_id')) {
                            DB::table('inventories_warehouses')->update(['branch_id' => null]);
                        }

                        if (Schema::hasTable('partners_partners') && Schema::hasColumn('partners_partners', 'branch_id')) {
                            DB::table('partners_partners')->update(['branch_id' => null]);
                        }
                    } finally {
                        Schema::enableForeignKeyConstraints();
                    }
                });
            })
            ->icon('branches');
    }

    public function packageBooted(): void
    {
        if (! Package::isPluginInstalled(static::$name)) {
            return;
        }

        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(BranchTransferSale::class, BranchTransferSalePolicy::class);
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(BranchPlugin::make());
        });

        $this->app->singleton(BranchService::class);
        $this->app->singleton(BranchTransferPriceService::class);
        $this->app->singleton(StockLayerService::class);
        $this->app->singleton(TransferStockService::class);
        $this->app->singleton(BranchTransferService::class);
    }
}
