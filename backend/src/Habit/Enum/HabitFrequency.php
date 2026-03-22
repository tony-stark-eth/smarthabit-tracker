<?php

declare(strict_types=1);

namespace App\Habit\Enum;

enum HabitFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case CUSTOM = 'custom';
}
