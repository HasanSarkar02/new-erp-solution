<?php

namespace Webkul\Branch\Enums;

use Filament\Support\Contracts\HasLabel;

enum WriteOffStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';
    case Forgiven = 'forgiven';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft    => 'Draft',
            self::Approved => 'Approved',
            self::Posted   => 'Posted',
            self::Forgiven => 'Forgiven',
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
