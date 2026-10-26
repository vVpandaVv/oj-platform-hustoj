<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeExecutionAvailabilityV1
{
    /** @param list<string> $languages */
    public function __construct(private readonly bool $available, private readonly array $languages,
        private readonly ?AddonApiProblemV1 $problem = null)
    {
        if ($available === ($problem !== null) || (! $available && $languages !== [])
            || ! array_is_list($languages) || count(array_unique($languages)) !== count($languages)) {
            throw new InvalidArgumentException('Invalid execution availability.');
        }
        foreach ($languages as $language) {
            Internal\ValueValidator::oneOf($language, ['python', 'c', 'cpp', 'java'], 'language');
        }
    }
    public function isAvailable(): bool { return $this->available; }
    /** @return list<string> */
    public function languages(): array { return $this->languages; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
