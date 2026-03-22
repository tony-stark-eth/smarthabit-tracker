<?php

declare(strict_types=1);

namespace App\Habit\Enum;

enum HabitLogSource: string
{
    case MANUAL = 'manual';
    case NOTIFICATION = 'notification';
}
