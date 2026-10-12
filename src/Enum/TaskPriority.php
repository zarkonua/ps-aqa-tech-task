<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $priority): string => $priority->value, self::cases());
    }
}
