<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum QuizReapplyMode: string
{
    case InProgress = 'in_progress';
    case Graded = 'graded';
}
