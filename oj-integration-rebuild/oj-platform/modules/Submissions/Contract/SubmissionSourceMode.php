<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionSourceMode
{
    public function key(): string;

    public function build(
        SubmissionSourceRequest $request
    ): SubmissionSourcePayload;
}
