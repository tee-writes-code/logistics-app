<?php

declare(strict_types=1);

namespace App\Enums;

enum FailReason: string
{
    case Closed = 'closed';
    case WrongSite = 'wrong_site';
    case NoContact = 'no_contact';
}
