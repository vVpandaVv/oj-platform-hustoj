<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Contract;

interface BlocklyAssets
{
    public function isReady(): bool;

    /**
     * @return array<string, string>
     */
    public function urls(): array;

    /**
     * @return array<string, array{
     *   path: string,
     *   exists: bool,
     *   integrity: bool,
     *   bytes: int,
     *   sha256: string
     * }>
     */
    public function diagnostics(): array;
}
