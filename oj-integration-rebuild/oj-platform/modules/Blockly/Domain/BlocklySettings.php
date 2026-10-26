<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Domain;

use InvalidArgumentException;

final class BlocklySettings
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $defaultProfile,
        private readonly int $maximumWorkspaceBytes
    ) {
        if (
            ! in_array(
                $this->defaultProfile,
                ['intro', 'standard', 'extended'],
                true
            )
            || $this->maximumWorkspaceBytes < 65536
            || $this->maximumWorkspaceBytes > 1048576
        ) {
            throw new InvalidArgumentException('Blockly 설정 범위가 올바르지 않습니다.');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function defaultProfile(): string
    {
        return $this->defaultProfile;
    }

    public function maximumWorkspaceBytes(): int
    {
        return $this->maximumWorkspaceBytes;
    }
}
