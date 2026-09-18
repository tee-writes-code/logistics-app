<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of plan an agent proposes when it needs human confirmation. Mirrors
 * the string-backed style of the other domain enums.
 */
enum AgentAskType: string
{
    case Next = 'next';
    case Return = 'return';
    case Unsafe = 'unsafe';
}
