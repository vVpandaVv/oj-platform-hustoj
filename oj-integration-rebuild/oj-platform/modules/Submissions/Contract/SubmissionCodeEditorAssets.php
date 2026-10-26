<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionCodeEditorAssets
{
    public function isReady(): bool;

    /** @return array{core: string, base: string} */
    public function urls(): array;
}
