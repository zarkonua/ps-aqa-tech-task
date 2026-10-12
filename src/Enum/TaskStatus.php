<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    /**
     * A task moves one step forward or back along the board; a finished task can be reopened.
     *
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Backlog => [self::Todo],
            self::Todo => [self::Backlog, self::InProgress],
            self::InProgress => [self::Todo, self::Done],
            self::Done => [self::InProgress],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
