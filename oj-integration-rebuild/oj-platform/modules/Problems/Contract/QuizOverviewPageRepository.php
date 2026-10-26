<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizOverview;

interface QuizOverviewPageRepository
{
    /** @return list<QuizOverview> */
    public function overviewPage(int $limit, int $offset): array;
}
