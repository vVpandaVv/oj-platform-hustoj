<?php
declare(strict_types=1);
namespace OjPrimm\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRuntimeContextV1;
use DomainException;
use OjPrimm\Application\LessonExecutor;
use Throwable;

/** Only the platform may access judge storage or execute untrusted programs. */
final class PlatformLessonExecutor implements LessonExecutor
{
    public function __construct(private readonly \Closure $runtimeProvider) {}
    public function availability(): array
    {
        try {
            $available = $this->runtime()->codeExecution()->availability();
            return ['available' => $available->isAvailable(), 'languages' => $available->languages(),
                'message' => $available->isAvailable() ? 'OJ 예제 자동 검증이 준비되었습니다.'
                    : ($available->problem()?->message() ?? 'OJ 채점기 연결과 예제 실행 기능을 설정해 주세요.')];
        } catch (Throwable) { return ['available' => false, 'languages' => [], 'message' => 'OJ Platform의 예제 실행 기능을 사용할 수 없습니다. 플랫폼 업데이트와 채점기 연결을 확인해 주세요.']; }
    }
    public function execute(int $actor, string $language, string $source, string $input, string $key, ?string $ticket): array
    {
        $gateway = $this->runtime()->codeExecution();
        $result = $ticket === null
            ? $gateway->submit(new AddonCodeExecutionRequestV1($actor, $language, $source, $input, 'primm-verify:' . hash('sha256', $key)))
            : $gateway->poll(new AddonCodeExecutionQueryV1($actor, $ticket));
        if (! $result->isSuccessful()) {
            if ($result->problem()?->retryable()) { throw new \OjPrimm\Generation\TransientGenerationException($result->problem()->message(), 0, $result->problem()->code()); }
            throw new \OjPrimm\Generation\GenerationFailure($result->problem()?->message() ?? 'OJ 예제 실행 요청을 확인하지 못했습니다. 채점기 연결을 확인해 주세요.', $result->problem()?->code() ?? 'execution_failure', false);
        }
        $payload = $result->payload();
        if (! in_array($payload['status'] ?? null, ['pending', 'completed'], true)
            || ! is_string($payload['ticket'] ?? null) || $payload['ticket'] === '') { throw new DomainException('OJ 예제 실행 응답이 올바르지 않습니다.'); }
        return $payload;
    }
    private function runtime(): AddonCodeExecutionRuntimeContextV1
    {
        $runtime = ($this->runtimeProvider)();
        if (! $runtime instanceof AddonCodeExecutionRuntimeContextV1 || $runtime->snapshot()->status() !== 'accepted') {
            throw new DomainException('OJ Platform의 승인된 예제 실행 연동이 준비되지 않았습니다.');
        }
        return $runtime;
    }
}
