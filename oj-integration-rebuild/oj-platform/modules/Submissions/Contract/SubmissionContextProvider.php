<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;

interface SubmissionContextProvider
{
    public function supports(SubmitSubmissionCommand $command): bool;

    public function validate(SubmitSubmissionCommand $command): SubmissionContext;
}
