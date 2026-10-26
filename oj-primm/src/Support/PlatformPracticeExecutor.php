<?php
declare(strict_types=1);
namespace OjPrimm\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRuntimeContextV1;
use DomainException;
use OjPrimm\Application\PracticeExecutor;

final class PlatformPracticeExecutor implements PracticeExecutor
{
    public function __construct(private readonly \Closure $runtimeProvider) {}
    public function execute(int $actor, array $material, string $source, int $caseIndex, string $key, ?string $ticket): array
    {
        $runtime = ($this->runtimeProvider)();
        if (! $runtime instanceof AddonPracticeRuntimeContextV1 || $runtime->snapshot()->status() !== 'accepted') { throw new DomainException('플랫폼의 학습자 연습 실행을 사용할 수 없습니다.'); }
        $definition = new AddonPracticeMaterialV1($material['activity_key'], $material['revision'], $material['context_type'],
            $material['context_id'], $material['language'], $material['tests']);
        $gateway = $runtime->practice();
        $result = $ticket === null
            ? $gateway->submit(new AddonPracticeRequestV1($actor, $definition, $source, $caseIndex, 'primm-practice:' . hash('sha256', $key)))
            : $gateway->poll(new AddonPracticeQueryV1($actor, $definition, $ticket));
        if (! $result->isSuccessful()) { throw new DomainException($result->problem()?->message() ?? '연습 실행 결과를 확인하지 못했습니다. 잠시 후 같은 요청을 다시 확인해 주세요.'); }
        return $result->payload();
    }
}
