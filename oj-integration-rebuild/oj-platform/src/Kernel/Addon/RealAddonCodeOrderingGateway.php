<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingPuzzleResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use DomainException;
use Throwable;

/** @internal Never projects canonical block order, schema, wrappers, or submission sources. */
final class RealAddonCodeOrderingGateway implements AddonCodeOrderingGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonLearningCheckGatewayV1 $learningChecks,
        private readonly SubmissionProblemProvider $problems,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function puzzle(AddonCodeOrderingQueryV1 $query): AddonCodeOrderingPuzzleResultV1
    {
        try {
            $schema = $this->schema($query);
            if ($schema instanceof AddonApiProblemV1) {
                return new AddonCodeOrderingPuzzleResultV1(false, [], $schema);
            }
            return new AddonCodeOrderingPuzzleResultV1(true, [
                'problem_id' => $query->problemId(),
                'language' => CodeOrderingPuzzle::languageKey($schema),
                'template_hash' => CodeOrderingPuzzle::hash($schema),
                'blocks' => CodeOrderingPuzzle::publicBlocks($schema),
            ], null);
        } catch (Throwable) {
            return new AddonCodeOrderingPuzzleResultV1(false, [], $this->unavailable());
        }
    }

    public function assemble(AddonCodeOrderingAnswerV1 $answer): AddonCodeOrderingAssemblyResultV1
    {
        try {
            $schema = $this->schema(new AddonCodeOrderingQueryV1(
                $answer->actorUserId(), $answer->problemId(), $answer->contextType(), $answer->contextId()
            ));
            if ($schema instanceof AddonApiProblemV1) {
                return new AddonCodeOrderingAssemblyResultV1(false, [], $schema);
            }
            $hash = CodeOrderingPuzzle::hash($schema);
            if (! hash_equals($hash, $answer->templateHash())) {
                return new AddonCodeOrderingAssemblyResultV1(false, [], $this->invalidAnswer());
            }
            try {
                $source = CodeOrderingPuzzle::assemble($schema, $answer->blockIds());
            } catch (DomainException) {
                return new AddonCodeOrderingAssemblyResultV1(false, [], $this->invalidAnswer());
            }
            return new AddonCodeOrderingAssemblyResultV1(true, [
                'problem_id' => $answer->problemId(),
                'language' => CodeOrderingPuzzle::languageKey($schema),
                'template_hash' => $hash,
                'source' => $source,
            ], null);
        } catch (Throwable) {
            return new AddonCodeOrderingAssemblyResultV1(false, [], $this->unavailable());
        }
    }

    /** @return string|AddonApiProblemV1 Server-only schema, after reauthorizing every request. */
    private function schema(AddonCodeOrderingQueryV1 $query): string|AddonApiProblemV1
    {
        $access = $this->learningChecks->context(new AddonLearningCheckContextQueryV1(
            $query->actorUserId(), $query->problemId(), null, $query->contextType(), $query->contextId()
        ));
        if (! $access->isSuccessful()) {
            return $access->problem()?->code() === 'addon_runtime_unavailable'
                ? $this->unavailable()
                : $this->problem('addon_access_denied', '이 코드 순서 배열 활동에 접근할 수 없습니다.', false);
        }
        if (($access->payload()['problem']['id'] ?? null) !== $query->problemId()) {
            return $this->problem('addon_access_denied', '이 코드 순서 배열 활동에 접근할 수 없습니다.', false);
        }
        $context = match ($query->contextType()) {
            'class' => ProblemAccessContext::class($query->contextId() ?? 0),
            'contest' => ProblemAccessContext::contest($query->contextId() ?? 0),
            default => ProblemAccessContext::general(),
        };
        // This public Problems contract rechecks problem visibility in the same context.
        // Group membership and contest feedback restrictions were checked above.
        $policy = $this->problems->codePolicy($query->actorUserId(), $query->problemId(), $context);
        if ($policy->problemId() !== $query->problemId()) {
            return $this->problem('addon_access_denied', '이 코드 순서 배열 활동에 접근할 수 없습니다.', false);
        }
        if ($policy->entryMode() !== 'code_ordering' || $policy->orderingSchema() === null) {
            return $this->problem('addon_context_unavailable', '코드 순서 배열로 설정된 문제가 아닙니다.', false);
        }
        $schema = $policy->orderingSchema();
        if (! hash_equals(CodeOrderingPuzzle::hash($schema), $policy->orderingHash() ?? '')) {
            return $this->unavailable();
        }
        return $schema;
    }

    private function unavailable(): AddonApiProblemV1
    {
        return $this->problem('addon_runtime_unavailable', '코드 순서 배열 자료를 사용할 수 없습니다.', true);
    }

    private function invalidAnswer(): AddonApiProblemV1
    {
        return $this->problem('addon_context_unavailable',
            '모든 코드 조각을 한 번씩 배치하고 문제 버전을 확인해 주세요.', false);
    }

    private function problem(string $code, string $message, bool $retryable): AddonApiProblemV1
    {
        return new AddonApiProblemV1($code, $message,
            $this->correlations->next($this->addonId . ':code-ordering'), $retryable);
    }
}
