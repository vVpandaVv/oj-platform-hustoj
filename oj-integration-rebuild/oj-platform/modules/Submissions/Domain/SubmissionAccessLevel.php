<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

enum SubmissionAccessLevel: string
{
    case Full = 'full';
    case ResultOnly = 'result_only';
    case Denied = 'denied';
}
