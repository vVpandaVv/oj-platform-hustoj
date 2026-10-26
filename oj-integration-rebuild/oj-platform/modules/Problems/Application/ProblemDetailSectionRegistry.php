<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionProvider;
use Throwable;

final class ProblemDetailSectionRegistry
{
    /** @var array<string, ProblemDetailSectionProvider> */
    private array $providers = [];

    public function register(ProblemDetailSectionProvider $provider): void
    {
        $key = trim($provider->key());
        if ($key === '' || isset($this->providers[$key])) {
            throw new \LogicException('문제 상세 Section Provider 키가 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
    }

    /**
     * @return list<ProblemDetailSection>
     */
    public function sections(int $problemId, int $userId): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            try {
                $section = $provider->provide($problemId, $userId, 5);
            } catch (Throwable) {
                continue;
            }
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
