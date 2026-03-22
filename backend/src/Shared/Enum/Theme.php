<?php

declare(strict_types=1);

namespace App\Shared\Enum;

enum Theme: string
{
    case AUTO = 'auto';
    case LIGHT = 'light';
    case DARK = 'dark';
}
