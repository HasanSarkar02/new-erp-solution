<?php

namespace Webkul\Branch\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransferStatus: string implements HasLabel
{
    case Draft = 'draft';

    case Confirmed = 'confirmed';

    case InTransit = 'in_transit';

    case PartiallyReceived = 'partially_received';

    case Received = 'received';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case Returned = 'returned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft             => 'Draft',
            self::Confirmed         => 'Confirmed',
            self::InTransit         => 'In Transit',
            self::PartiallyReceived => 'Partially Received',
            self::Received          => 'Received',
            self::Completed         => 'Completed',
            self::Cancelled         => 'Cancelled',
            self::Returned          => 'Returned',
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

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Returned], true);
    }
}
