<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;

interface QuizBankRepository
{
    public function find(int $id): ?QuizBankQuestion;

    /**
     * @return list<QuizBankQuestion>
     */
    public function search(string $keyword): array;

    public function save(?int $id, QuizQuestion $question, int $actorUserId): QuizBankQuestion;

    public function delete(int $id): void;

    public function usageCount(int $id): int;
}
