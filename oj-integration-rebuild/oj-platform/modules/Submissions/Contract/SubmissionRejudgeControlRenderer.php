<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionRejudgeControlRenderer
{
    public function render(string $returnPath): string;
}
