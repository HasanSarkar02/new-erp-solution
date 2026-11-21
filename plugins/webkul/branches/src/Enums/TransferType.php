<?php

namespace Webkul\Branch\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransferType: string implements HasLabel
{
    case Supply = 'supply';

    case Rebalance = 'rebalance';

    case Return = 'return';

    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Supply    => 'Supply (Main → Sub)',
            self::Rebalance => 'Rebalance (Sub → Sub)',
            self::Return    => 'Return (Sub → Main)',
            self::Service   => 'Service',
        };
    }

    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
