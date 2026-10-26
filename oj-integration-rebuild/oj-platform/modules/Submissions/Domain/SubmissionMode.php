<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

enum SubmissionMode: string
{
    case Code = 'code';
    case CodeCompletion = 'code_completion';
    case CodeOrdering = 'code_ordering';
    case Blockly = 'blockly';
}
