<?php

namespace Webkul\Branch;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Webkul\Branch\Filament\Widgets\BranchesOweUsWidget;
use Webkul\PluginManager\Package;

class BranchPlugin implements Plugin
{
    public function getId(): string
    {
        return 'branches';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        if (! Package::isPluginInstalled($this->getId())) {
            return;
        }

        $panel
            ->when($panel->getId() == 'admin', function (Panel $panel) {
                /*
                 * Menu fix (Phase 2): the admin panel registers its
                 * navigation groups from the core NavigationGroup enum
                 * (AdminPanelProvider). Our resources returned the plain
                 * string 'Branches', which matches no registered group,
                 * so Filament built it as an ad-hoc group sorted last
                 * among ~20 groups in the top navigation — effectively
                 * invisible. Registering the group here (navigationGroups
                 * merges, it does not replace the panel's groups) makes
                 * 'Branches' a first-class group with a label and icon,
                 * so Branches, Internal Transfers and Transfer Price
                 * Rules actually appear in the menu.
                 */
                $panel->navigationGroups([
                    'Branches' => NavigationGroup::make()
                        ->label('Branches')
                        ->icon('heroicon-o-building-office'),
                ]);

                $panel->discoverResources(
                    in: __DIR__.'/Filament/Resources',
                    for: 'Webkul\\Branch\\Filament\\Resources'
                )
                    ->discoverPages(
                        in: __DIR__.'/Filament/Pages',
                        for: 'Webkul\\Branch\\Filament\\Pages'
                    )
                    ->discoverWidgets(
                        in: __DIR__.'/Filament/Widgets',
                        for: 'Webkul\\Branch\\Filament\\Widgets'
                    )
                    // "Branches owe us" on the admin dashboard (Phase 5).
                    ->widgets([
                        BranchesOweUsWidget::class,
                    ]);
            });
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
