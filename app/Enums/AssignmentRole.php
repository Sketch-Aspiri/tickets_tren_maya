<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentRole: string
{
    case Responsable = 'responsable';
    case Colaborador = 'colaborador';

    public function label(): string
    {
        return __('enums.assignment_role.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
