<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobView extends JobAccessContext
{
    public function status(): string;

    public function succeededCount(): int;

    public function isTerminal(): bool;

    public function resultMessage(): ?string;
}
