<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentType: string
{
    case Dispatch = 'dispatch';
    case Exception = 'exception';
    case CustomerOps = 'customer_ops';
}
