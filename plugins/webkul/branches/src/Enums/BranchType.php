<?php

namespace Webkul\Branch\Enums;

use Filament\Support\Contracts\HasLabel;

enum BranchType: string implements HasLabel
{
    case Main = 'main';

    case Sub = 'sub';

    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Main    => 'Main / Central',
            self::Sub     => 'Sub Branch (Showroom)',
            self::Service => 'Service Branch',
        };
    }

    public static function options(): array
    {
        return [
            self::Main->value    => self::Main->getLabel(),
            self::Sub->value     => self::Sub->getLabel(),
            self::Service->value => self::Service->getLabel(),
        ];
    }

    public function holdsStock(): bool
    {
        return $this !== self::Service;
    }
}
