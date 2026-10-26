<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum ProblemBookVisibility: string
{
    case Members = 'members';
    case Private = 'private';
}
