<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use DomainException;

final class ProblemCodePolicyService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ?VisualGradingTemplateSource $visualTemplates = null
    ) {
    }

    public function forProblem(ProblemId $problemId): ProblemSubmissionCodePolicy
    {
        $problem = $this->problems->find($problemId);
        if ($problem === null) {
            throw new DomainException('문제 제출 정책을 찾을 수 없습니다.');
        }
        $metadata = $this->metadata->find($problemId);
        $mode = $metadata?->codeEntryMode() ?? CodeEntryMode::FullCode;
        $fragments = $problem->forbiddenWords();
        $front = $problem->prefixCode();
        $rear = $problem->suffixCode();
        $visualMode = $metadata?->visualGradingMode() ?? VisualGradingMode::None;
        $visualHash = null;
        if ($visualMode->isEnabled()) {
            if ($this->visualTemplates === null) {
                throw new DomainException('이미지 채점 구성을 사용할 수 없습니다.');
            }
            $wrapper = $this->visualTemplates->wrapper($visualMode);
            $hiddenPrefix = $wrapper->hiddenPrefix();
            $hiddenSuffix = $wrapper->hiddenSuffix();
            $visualHash = hash('sha256', $hiddenPrefix . "\n--\n" . $hiddenSuffix);
            $front = $front === '' ? $hiddenPrefix : $hiddenPrefix . "\n" . $front;
            $rear = $rear === '' ? $hiddenSuffix : $rear . "\n" . $hiddenSuffix;
        }
        $canonical = [
            'problem_id' => $problemId->value(),
            'mode' => $mode->value,
            'forbidden_fragments' => $fragments,
            'front' => $front,
            'body' => $problem->starterCode(),
            'rear' => $rear,
            'langmask' => $problem->languageMask(),
            'completion_hash' => $metadata?->completionHash(),
            'ordering_hash' => $metadata?->orderingHash(),
        ];
        if ($visualHash !== null) {
            $canonical['visual_mode'] = $visualMode->value;
            $canonical['visual_wrapper'] = $visualHash;
        }

        return new ProblemSubmissionCodePolicy(
            $problemId,
            $mode,
            $fragments,
            $front,
            $problem->starterCode(),
            $rear,
            $problem->languageMask(),
            $metadata?->completionSchema(),
            $metadata?->completionHash(),
            'problem-code-policy-v1:' . hash(
                'sha256',
                (string) json_encode(
                    $canonical,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ),
            $metadata?->orderingSchema(),
            $metadata?->orderingHash()
        );
    }
}
