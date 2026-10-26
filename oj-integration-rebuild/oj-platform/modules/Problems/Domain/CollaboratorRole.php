<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum CollaboratorRole: string
{
    case Editor = 'editor';
    case TestcaseManager = 'testcase_manager';
}
