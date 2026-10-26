<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

use InvalidArgumentException;

final class CompositeSetupReadinessProbe implements SetupReadinessProbe
{
    /** @var list<SetupReadinessProbe> */
    private readonly array $probes;

    /** @param list<SetupReadinessProbe> $probes */
    public function __construct(array $probes)
    {
        foreach ($probes as $probe) {
            if (! $probe instanceof SetupReadinessProbe) {
                throw new InvalidArgumentException('설치 준비 검사기가 올바르지 않습니다.');
            }
        }

        $this->probes = array_values($probes);
    }

    public function blockers(): array
    {
        $blockers = [];
        foreach ($this->probes as $probe) {
            foreach ($probe->blockers() as $blocker) {
                if (is_string($blocker) && trim($blocker) !== '') {
                    $blockers[] = trim($blocker);
                }
            }
        }

        return array_values(array_unique($blockers));
    }
}
