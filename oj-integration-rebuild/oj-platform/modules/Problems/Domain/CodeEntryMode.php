<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum CodeEntryMode: string
{
    case FullCode = 'full_code';
    case CodeCompletion = 'code_completion';
    case CodeOrdering = 'code_ordering';
}
