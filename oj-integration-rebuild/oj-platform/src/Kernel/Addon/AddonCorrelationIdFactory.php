<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

/** @internal */
final class AddonCorrelationIdFactory
{
    private int $sequence = 0;

    public function next(string $scope): string
    {
        ++$this->sequence;

        return 'addon-' . substr(hash(
            'sha256',
            $scope . '|' . (string) $this->sequence . '|' . microtime(true)
        ), 0, 24);
    }
}
