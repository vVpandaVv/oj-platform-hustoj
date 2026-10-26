<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

interface JudgeResults
{
    public function find(int $code): JudgeResult;

    /**
     * @return list<JudgeResult>
     */
    public function all(): array;
}
