<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Domain\AiUsageFilter;

interface AiUsageStore
{
    /** Metadata only: never prompt, output, source, endpoint or credential. */
    public function append(array $record): void;

    /** @return array{summary:array,groups:array,items:array,page:int,pages:int} */
    public function report(AiUsageFilter $filter): array;
}
