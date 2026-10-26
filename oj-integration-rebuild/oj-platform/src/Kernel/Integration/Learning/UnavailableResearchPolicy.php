<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\RecommendationPolicy;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionResearchRecorder;
use CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy;
use DomainException;

/** Preserves restrictions across a companion-plugin disable, missing file or upgrade gap. */
final class UnavailableResearchPolicy implements FeedbackResearchPolicy, ActorAiAccessPolicy, SubmissionResearchRecorder, RecommendationPolicy, AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $db) {}

    public function context(AiFeedbackSubmission $submission): ?array
    {
        $this->assertAiAccess($submission->ownerUserId());
        return null;
    }

    public function assertAiAccess(int $actorUserId): void
    {
        if ($this->hasTable('research_participants') && $this->count(
            'SELECT COUNT(*) FROM research_participants WHERE active_user_id = %d', [$actorUserId]
        ) > 0) {
            throw new DomainException('진행 중인 연구를 확인하려면 OJ Research 플러그인을 다시 활성화해 주세요.');
        }
    }

    public function bindSubmission(int $actor, int $problemId, int $submissionId): void { $this->assertAiAccess($actor); }
    public function recommendationIds(int $userId): ?array { $this->assertAiAccess($userId); return null; }
    public function record(array $context, string $event, array $data): void
    {
        throw new DomainException('연구 플러그인이 없어 연구 응답을 기록할 수 없습니다.');
    }

    public function moduleKey(): string { return 'research'; }
    public function blockers(int $userId): array
    {
        // The companion owns erasure of participant, reviewer and record payloads.
        // Do not silently omit that participant when its plugin is unavailable.
        return $this->hasTable('research_studies') && $this->count('SELECT COUNT(*) FROM research_studies') > 0
            ? [new AccountErasureBlocker('research', 'research.plugin_missing', '연구 자료의 개인정보 정리를 위해 OJ Research를 활성화한 뒤 계정을 삭제해 주세요.', 1)]
            : [];
    }
    public function anonymize(int $userId, string $currentUserLogin, string $anonymousUserLogin): void
    {
        if ($this->blockers($userId) !== []) { throw new DomainException('연구 자료 정리에 OJ Research가 필요합니다.'); }
    }
    public function verify(int $userId, string $anonymousUserLogin): bool { return $this->blockers($userId) === []; }

    private function hasTable(string $name): bool
    {
        return $this->count('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', [$name]) > 0;
    }
    private function count(string $sql, array $values = []): int
    {
        $value = $this->db->value($sql, $values);
        if (! is_numeric($value)) { throw new DomainException('연구 제한 상태를 확인할 수 없습니다. 잠시 후 다시 시도해 주세요.'); }
        return (int) $value;
    }
}
