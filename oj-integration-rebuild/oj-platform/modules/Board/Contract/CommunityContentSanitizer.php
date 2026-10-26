<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

interface CommunityContentSanitizer
{
    public function title(string $value): string;

    public function body(string $value): string;

    public function comment(string $value): string;
}
