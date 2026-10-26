<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

interface ProtectedAttachmentRootProvider
{
    public function root(): string;
}
