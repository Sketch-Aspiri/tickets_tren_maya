<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __('enums.user_status.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
