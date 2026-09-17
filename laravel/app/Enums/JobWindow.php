<?php

declare(strict_types=1);

namespace App\Enums;

enum JobWindow: string
{
    case SameDay = 'same_day';
    case Next = 'next';
}
