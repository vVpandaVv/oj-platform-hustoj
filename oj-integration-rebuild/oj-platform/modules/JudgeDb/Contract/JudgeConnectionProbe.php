<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

interface JudgeConnectionProbe
{
    public function inspect(JudgeConnectionSettings $settings): JudgeReadinessReport;
}
