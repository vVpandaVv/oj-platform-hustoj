<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use LogicException;

final class ResearchPolicyBridge implements FeedbackResearchPolicy
{
    private ?FeedbackResearchPolicy $policy = null;

    public function attach(FeedbackResearchPolicy $policy): void
    {
        if ($this->policy !== null) {
            throw new LogicException('연구 정책이 이미 연결되었습니다.');
        }
        $this->policy = $policy;
    }

    public function context(AiFeedbackSubmission $submission): ?array
    {
        if ($this->policy === null) {
            throw new LogicException('연구 정책을 확인할 수 없습니다.');
        }
        return $this->policy->context($submission);
    }

    public function record(array $context, string $event, array $data): void
    {
        if ($this->policy === null) {
            throw new LogicException('연구 기록을 저장할 수 없습니다.');
        }
        $this->policy->record($context, $event, $data);
    }
}
