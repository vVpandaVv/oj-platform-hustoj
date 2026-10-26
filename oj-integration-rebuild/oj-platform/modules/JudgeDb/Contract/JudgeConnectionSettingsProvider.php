<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

interface JudgeConnectionSettingsProvider
{
    public function current(): ?JudgeConnectionSettings;
}
