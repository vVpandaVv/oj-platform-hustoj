<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;

interface SubmissionEditorExtension
{
    public function key(): string;

    public function label(): string;

    public function isAvailable(): bool;

    public function render(
        SubmissionCodePolicy $policy,
        ?string $forcedProfile = null
    ): string;
}
