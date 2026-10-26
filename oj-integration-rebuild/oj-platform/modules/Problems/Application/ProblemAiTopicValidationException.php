<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use DomainException;

final class ProblemAiTopicValidationException extends DomainException
{
    /** @param list<array{key:string,label:string,reason:string}> $issues */
    public function __construct(private readonly array $issues)
    {
        parent::__construct('AI가 선택할 수 없는 주제를 반환했습니다. 주제 검증 상세를 확인해 주세요.');
    }

    /** @return list<array{key:string,label:string,reason:string}> */
    public function issues(): array
    {
        return $this->issues;
    }
}
