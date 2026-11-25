<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

enum Survivorship: string
{
    case First = 'first';
    case Last = 'last';
}
