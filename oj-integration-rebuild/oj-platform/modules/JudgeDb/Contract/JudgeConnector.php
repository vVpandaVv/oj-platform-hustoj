<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

interface JudgeConnector
{
    public function read(JudgeConnectionSettings $settings): \mysqli;

    public function write(JudgeConnectionSettings $settings): \mysqli;
}
