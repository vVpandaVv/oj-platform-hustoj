<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum QuizResultPolicy: string
{
    case Full = 'full';
    case ScoreOnly = 'score_only';
    case AfterEnd = 'after_end';
}
