<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionTemplateResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeCompletionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use DomainException;
use Throwable;

/** @internal Only public template metadata and the learner's reconstruction leave this gateway. */
final class RealAddonCodeCompletionGateway implements AddonCodeCompletionGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonLearningCheckGatewayV1 $learningChecks,
        private readonly SubmissionProblemProvider $problems,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly SubmissionLanguageCatalog $languages
    ) {
    }

    public function template(AddonCodeCompletionQueryV1 $query): AddonCodeCompletionTemplateResultV1
    {
        try {
            $policy = $this->policy($query);
            if ($policy instanceof AddonApiProblemV1) {
                return new AddonCodeCompletionTemplateResultV1(false, [], $policy);
            }
            $schema = $this->schema($policy);
            return new AddonCodeCompletionTemplateResultV1(true, [
                'problem_id' => $query->problemId(),
                'language' => strtolower($schema['language_key']),
                'template_hash' => $policy->completionHash(),
                'starter_code' => $policy->starterCode(),
                'blanks' => $schema['blanks'],
            ], null);
        } catch (Throwable) {
            return new AddonCodeCompletionTemplateResultV1(false, [], $this->unavailable());
        }
    }

    public function assemble(AddonCodeCompletionAnswerV1 $answer): AddonCodeCompletionAssemblyResultV1
    {
        try {
            $policy = $this->policy(new AddonCodeCompletionQueryV1(
                $answer->actorUserId(), $answer->problemId(), $answer->contextType(), $answer->contextId()
            ));
            if ($policy instanceof AddonApiProblemV1) {
                return new AddonCodeCompletionAssemblyResultV1(false, [], $policy);
            }
            $schema = $this->schema($policy);
            try {
                // Keep normalization, inline/block, byte limits, IDs and reconstruction
                // identical to the existing formal code-completion submission mode.
                $source = (new CodeCompletionSourceMode($this->languages))->build(new SubmissionSourceRequest(
                    $policy, $this->languages->idForKey($schema['language_key']), '',
                    $answer->templateHash(), $answer->answers(), '', ''
                ));
            } catch (DomainException) {
                return new AddonCodeCompletionAssemblyResultV1(false, [], $this->problem('addon_context_unavailable',
                    '문제 버전과 모든 빈칸의 형식·길이를 확인해 주세요.', false));
            }
            return new AddonCodeCompletionAssemblyResultV1(true, [
                'problem_id' => $answer->problemId(),
                'language' => strtolower($schema['language_key']),
                'template_hash' => $policy->completionHash(),
                'source' => $source->userSource(),
            ], null);
        } catch (Throwable) {
            return new AddonCodeCompletionAssemblyResultV1(false, [], $this->unavailable());
        }
    }

    private function policy(AddonCodeCompletionQueryV1 $query): SubmissionCodePolicy|AddonApiProblemV1
    {
        $access = $this->learningChecks->context(new AddonLearningCheckContextQueryV1(
            $query->actorUserId(), $query->problemId(), null, $query->contextType(), $query->contextId()
        ));
        if (! $access->isSuccessful()) {
            return $access->problem()?->code() === 'addon_runtime_unavailable'
                ? $this->unavailable()
                : $this->denied();
        }
        if (($access->payload()['problem']['id'] ?? null) !== $query->problemId()) {
            return $this->denied();
        }
        $context = match ($query->contextType()) {
            'class' => ProblemAccessContext::class($query->contextId() ?? 0),
            'contest' => ProblemAccessContext::contest($query->contextId() ?? 0),
            default => ProblemAccessContext::general(),
        };
        $policy = $this->problems->codePolicy($query->actorUserId(), $query->problemId(), $context);
        if ($policy->problemId() !== $query->problemId()) { return $this->denied(); }
        if ($policy->entryMode() !== 'code_completion') {
            return $this->problem('addon_context_unavailable', '코드 빈칸 채우기로 설정된 문제가 아닙니다.', false);
        }
        return $policy;
    }

    /** @return array{language_key:string,blanks:list<array{id:string,label:string,kind:string,max_bytes:int}>} */
    private function schema(SubmissionCodePolicy $policy): array
    {
        $prepared = (new CodeCompletionTemplatePolicy())->prepare(
            $policy->starterCode(), $policy->completionSchema() ?? '', $policy->disabledLanguageMask()
        );
        if (! hash_equals($prepared['hash'], $policy->completionHash() ?? '')) {
            throw new DomainException('Inconsistent code completion policy.');
        }
        // The authoring policy projects only language_key and the four public blank
        // fields, dropping any extra schema fields instead of forwarding raw JSON.
        $schema = json_decode($prepared['schema'], true, 32, JSON_THROW_ON_ERROR);
        $this->languages->idForKey($schema['language_key']);
        return $schema;
    }

    private function denied(): AddonApiProblemV1
    {
        return $this->problem('addon_access_denied', '이 코드 빈칸 채우기 활동에 접근할 수 없습니다.', false);
    }

    private function unavailable(): AddonApiProblemV1
    {
        return $this->problem('addon_runtime_unavailable', '코드 빈칸 채우기 자료를 사용할 수 없습니다.', true);
    }

    private function problem(string $code, string $message, bool $retryable): AddonApiProblemV1
    {
        return new AddonApiProblemV1($code, $message,
            $this->correlations->next($this->addonId . ':code-completion'), $retryable);
    }
}
