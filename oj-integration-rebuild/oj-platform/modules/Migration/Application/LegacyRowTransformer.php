<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use DomainException;

final class LegacyRowTransformer
{
    /** @var list<LegacyDomainTransformer> */
    private readonly array $transformers;

    /** @param list<LegacyDomainTransformer> $transformers */
    public function __construct(array $transformers)
    {
        foreach ($transformers as $transformer) {
            if (! $transformer instanceof LegacyDomainTransformer) {
                throw new DomainException('이전 변환기 구성이 올바르지 않습니다.');
            }
        }
        $this->transformers = array_values($transformers);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public function transform(
        MigrationTable $table,
        array $row,
        MigrationPlan $plan
    ): array {
        if ($table->transformer() === 'none') {
            return [];
        }
        if ($table->transformer() === 'identity') {
            return [$row];
        }
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($table->transformer())) {
                return $transformer->transform($table, $row, $plan);
            }
        }

        throw new DomainException('등록되지 않은 이전 변환기입니다.');
    }
}

