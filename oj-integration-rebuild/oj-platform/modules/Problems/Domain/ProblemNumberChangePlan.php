<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemNumberChangePlan
{
    /**
     * @param array<string, array<string, int>> $impactCounts
     */
    public function __construct(
        private readonly ProblemNumberMap $requested,
        private readonly ProblemNumberMap $temporary,
        private readonly array $impactCounts
    ) {
        if (
            $this->requested->sourceIds() !== $this->temporary->sourceIds()
            || array_intersect(
                $this->temporary->targetIds(),
                array_merge(
                    $this->requested->sourceIds(),
                    $this->requested->targetIds()
                )
            ) !== []
        ) {
            throw new InvalidArgumentException('문제 번호 임시 전환 계획이 올바르지 않습니다.');
        }
    }

    public function requested(): ProblemNumberMap
    {
        return $this->requested;
    }

    public function temporary(): ProblemNumberMap
    {
        return $this->temporary;
    }

    public function finalStage(): ProblemNumberMap
    {
        $pairs = [];
        foreach ($this->requested->pairs() as $source => $target) {
            $temporary = $this->temporary->pairs()[$source];
            $pairs[$temporary] = $target;
        }

        return new ProblemNumberMap($pairs);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function impactCounts(): array
    {
        return $this->impactCounts;
    }

    public function hash(): string
    {
        return hash(
            'sha256',
            $this->requested->hash()
                . ':' . $this->temporary->hash()
                . ':' . $this->impactHash()
        );
    }

    private function impactHash(): string
    {
        $impact = $this->impactCounts;
        ksort($impact, SORT_STRING);
        foreach ($impact as &$counts) {
            ksort($counts, SORT_STRING);
        }
        unset($counts);

        return hash(
            'sha256',
            (string) json_encode($impact, JSON_UNESCAPED_SLASHES)
        );
    }
}
