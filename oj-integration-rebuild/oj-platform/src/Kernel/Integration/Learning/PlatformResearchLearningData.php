<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchLearningDataV1;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use DomainException;

final class PlatformResearchLearningData implements ResearchLearningDataV1
{
    public function __construct(private readonly LearnerLevelService $levels, private readonly WordPressUserGateway $users, private readonly SubmissionProjectionProvider $submissions, private readonly LearningProblemProvider $problems,
        private readonly ?\CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService $feedback = null) {}

    public function guides(): array
    {
        $result = [];
        foreach (['insufficient','beginner','intermediate','advanced'] as $level) {
            $guide = $this->feedback?->selectedGuide($level === 'insufficient' ? null : $level);
            if ($guide === null) { throw new DomainException('활성 수준별 AI 지침을 먼저 설정해 주세요.'); }
            $result[$level] = ['key'=>$guide->key(),'revision'=>$guide->revision(),'instructions'=>$guide->instructions()];
        }
        return $result;
    }

    public function problems(int $actor, array $ids): array
    {
        $result = [];
        foreach ($this->problems->findManyForUser($actor, $ids) as $problem) {
            $result[$problem->problemId()] = ['id'=>$problem->problemId(),'title'=>$problem->title(),
                'difficulty'=>$problem->effectiveDifficulty(),'topics'=>$problem->topicKeys(),'hash'=>$problem->contentHash()];
        }
        return $result;
    }

    public function levelSnapshot(int $userId): array
    {
        return ($this->levels->find($userId) ?? $this->levels->refresh($userId))->toRow();
    }

    public function submissions(int $userId, array $problemIds, string $from, string $until): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) { throw new DomainException('학습 기록의 학생 계정을 찾을 수 없습니다.'); }
        $result = [];
        $offset = 0;
        do {
            $page = $this->submissions->report(new SubmissionProjectionQuery([$user->userLogin()], $problemIds, null, null, null, true, 1000, $offset, null, $from, $until));
            foreach ($page as $s) {
                $result[] = ['submission_id' => $s->submissionId(), 'problem_id' => $s->problemId(), 'result_code' => $s->resultCode(),
                    'processing' => $s->isProcessing(), 'language_id' => $s->languageId(), 'submitted_at' => $s->submittedAt(), 'pass_rate'=>$s->passRate()];
            }
            $offset += count($page);
            if (count($page) === 1000 && $offset >= 10000) { throw new DomainException('차시 제출 기록이 조회 한도를 넘었습니다. 자료를 별도로 확인해 주세요.'); }
        } while (count($page) === 1000);
        return $result;
    }

    public function problemHashes(int $actorUserId, array $problemIds): array
    {
        $hashes = [];
        foreach (array_unique($problemIds) as $id) {
            $problem = $this->problems->findForUser($actorUserId, $id);
            if ($problem === null) { throw new DomainException('현재 접근 가능한 학습 문제를 지정해 주세요.'); }
            $hashes[$id] = $problem->contentHash();
        }
        ksort($hashes);
        return $hashes;
    }
}
