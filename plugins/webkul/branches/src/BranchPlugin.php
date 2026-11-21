<?php

namespace Webkul\Branch;

use Filament\Contracts\Plugin;
use Filament\Panel;
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
                $panel->discoverResources(
                    in: __DIR__.'/Filament/Resources',
                    for: 'Webkul\\Branch\\Filament\\Resources'
                );
            });
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
