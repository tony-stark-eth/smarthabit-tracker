<?php

declare(strict_types=1);

namespace App\Habit\Enum;

enum TimeWindowMode: string
{
    case MANUAL = 'manual';
    case AUTO = 'auto';
}
