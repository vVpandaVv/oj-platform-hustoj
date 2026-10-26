<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;

interface FeedbackResearchPolicy
{
    /** Null means ordinary feedback; denied research access throws. @return array<string,mixed>|null */
    public function context(AiFeedbackSubmission $submission): ?array;

    /** @param array<string,mixed> $context @param array<string,mixed> $data */
    public function record(array $context, string $event, array $data): void;
}
