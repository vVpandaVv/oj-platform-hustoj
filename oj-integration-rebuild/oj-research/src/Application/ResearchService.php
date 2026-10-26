<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjResearch\Contract\ResearchLearningGateway;
use CodingPanda\OjResearch\Contract\ResearchRepository;
use CodingPanda\OjResearch\Domain\ResearchProtocol;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ResearchService implements FeedbackResearchPolicy, ActorAiAccessPolicy
{
    private ?PilotService $pilot = null;
    public function attachPilot(PilotService $pilot): void
    {
        if ($this->pilot !== null) { throw new \LogicException('Pilot service already attached.'); }
        $this->pilot = $pilot;
    }
    public function __construct(
        private readonly ResearchRepository $store,
        private readonly GroupContextAccess $groups,
        private readonly ResearchLearningGateway $learning,
        private readonly WordPressUserGateway $users,
        private readonly Authorization $permissions,
        private readonly FeatureFlags $features,
        private readonly AiTaskRouter $ai,
        private readonly Clock $clock
    ) {}

    public function managedGroups(int $actor): array
    {
        $result = [];
        foreach ($this->groups->managedBy($actor) as $group) {
            if ($group->isEnabled() && ! $group->isDeleted()) { $result[$group->id()] = $group->name(); }
        }
        return $result;
    }

    public function authorizeProtocol(int $actor, array $protocol): void
    {
        $this->assertManager($actor, ['data'=>['protocol'=>$protocol]]);
    }

    public function listFor(int $actor): array
    {
        $result = [];
        foreach ($this->store->studies() as $study) {
            try { $this->assertManager($actor, $study); $result[] = $study; } catch (DomainException) {}
        }
        return $result;
    }

    public function create(int $actor, array $input): int
    {
        $this->assertEnabled();
        $protocol = ResearchProtocol::validate($input, $actor);
        $study = ['title' => trim($input['title']), 'owner_id' => $actor, 'status' => 'draft', 'revision' => 1,
            'data' => ['protocol' => $protocol, 'lesson' => 0, 'phase' => 'draft', 'phase_started_at' => $this->now()],
            'created_at' => $this->now(), 'updated_at' => $this->now()];
        $this->assertManager($actor, $study);
        foreach ($protocol['raters'] as $rater) {
            if ($this->users->findById($rater) === null || ! $this->permissions->can($rater, 'oj_access_console')) { throw new DomainException('OJ Console을 사용할 수 있는 평가자 계정을 지정해 주세요.'); }
        }
        return $this->store->transaction(function () use ($study, $actor): int {
            $id = $this->store->create($study);
            $this->store->append($id, 0, 'created', 'study.created', ['actor' => $actor], $this->now());
            return $id;
        });
    }

    public function managed(int $actor, int $id): array
    {
        $study = $this->requireStudy($id);
        $this->assertManager($actor, $study);
        return $study;
    }

    public function roster(int $actor, int $studyId): array
    {
        $study = $this->managed($actor, $studyId);
        $roster = [];
        foreach ($study['data']['protocol']['groups'] as $groupId) {
            foreach ($this->groups->activeLearnerUserIds($groupId) as $userId) {
                if (! isset($roster[$userId])) {
                    $identity = $this->users->findById($userId);
                    if ($identity !== null) { $roster[$userId] = ['user_id' => $userId, 'group_id' => $groupId, 'name' => $identity->displayName()]; }
                }
            }
        }
        ksort($roster);
        return $roster;
    }

    public function enroll(int $actor, int $studyId, int $revision, array $consentedUserIds): void
    {
        $this->assertEnabled();
        $study = $this->managed($actor, $studyId);
        $roster = $this->roster($actor, $studyId);
        $ids = array_values(array_unique(array_map('intval', $consentedUserIds)));
        sort($ids, SORT_NUMERIC);
        if (count($ids) < 2 || count($ids) > 1000) { throw new DomainException('동의가 확인된 연구 대상자를 2~1000명 선택해 주세요.'); }
        $snapshots = [];
        foreach ($ids as $userId) {
            if (! isset($roster[$userId]) || in_array($userId, $study['data']['protocol']['raters'], true)) {
                throw new DomainException('현재 명단 또는 평가자와 연구 대상자의 역할을 확인해 주세요.');
            }
            // Use the established learner assessment unchanged, before any independent research test opens.
            $snapshots[$userId] = $this->learning->levelSnapshot($userId);
        }
        $routing = $this->ai->snapshot('submission_feedback', true);
        $problemIds = [];
        foreach ($study['data']['protocol']['lessons'] as $lesson) { $problemIds = array_merge($problemIds, $lesson['problems']); }
        $problemHashes = $this->learning->problemHashes($actor, $problemIds);
        if ($routing->fallbackProfileKey() !== null) { throw new DomainException('연구에는 대체 모델이 없는 고정 AI 연결을 사용해 주세요.'); }
        $this->store->transaction(function () use ($actor, $studyId, $revision, $ids, $snapshots, $routing, $roster, $problemHashes): void {
            $study = $this->locked($actor, $studyId, $revision);
            if ($study['status'] !== 'draft') { throw new DomainException('초기 자료는 연구 준비 단계에서 한 번 저장합니다.'); }
            if ($this->roster($actor, $studyId) !== $roster) { throw new DomainException('그룹 명단이 변경되었습니다. 다시 확인해 주세요.'); }
            foreach ($ids as $userId) {
                if ($this->store->activeForUser($userId) !== null) { throw new DomainException('이미 다른 활성 연구에 참여 중인 학생이 있습니다.'); }
                $this->store->addParticipant(['study_id' => $studyId, 'user_id' => $userId,
                    'group_id' => $roster[$userId]['group_id'], 'active_user_id' => $userId,
                    'pseudonym' => 'R-' . bin2hex(random_bytes(8)), 'arm' => null, 'consent' => 'granted', 'baseline' => $snapshots[$userId]]);
            }
            $study['data']['routing'] = $routing->toArray();
            $study['data']['problem_hashes'] = $problemHashes;
            $study['data']['baseline_at'] = $this->now();
            $study['data']['protocol_hash'] = ResearchProtocol::hash($study['data']['protocol']);
            $study['data']['blind_secret'] = bin2hex(random_bytes(32));
            $this->savePhase($study, 'baseline', $actor);
        });
    }

    public function participants(int $actor, int $studyId): array
    {
        $this->managed($actor, $studyId);
        return $this->store->participants($studyId);
    }

    public function allocate(int $actor, int $studyId, int $revision): void
    {
        $this->store->transaction(function () use ($actor, $studyId, $revision): void {
            $study = $this->locked($actor, $studyId, $revision);
            if ($study['status'] !== 'allocation_ready') { throw new DomainException('사전검사를 마친 뒤 배정해 주세요.'); }
            $participants = array_values(array_filter($this->store->participants($studyId), static fn (array $p): bool => $p['consent'] === 'granted'));
            if (count($participants) < 2) { throw new DomainException('배정 가능한 연구 참여자가 부족합니다.'); }
            foreach ($participants as &$participant) {
                $outcome = $this->outcome($study, $participant, 'pretest');
                if ($outcome['status'] === 'pending') { throw new DomainException('사전검사 두 평가자의 채점 또는 미응시 사유를 완료해 주세요.'); }
                $participant['pre_score'] = $outcome['score'];
            }
            unset($participant);
            $seed = bin2hex(random_bytes(32));
            $allocation = ResearchProtocol::allocate($participants, $seed);
            foreach ($allocation as $id => $arm) { $this->store->updateParticipant($id, ['arm' => $arm]); }
            $study['data']['allocation'] = ['algorithm' => 'within_group_level_pretest_pairs_v1', 'seed' => $seed,
                'input_hash' => ResearchProtocol::hash($participants), 'assigned_at' => $this->now()];
            $study['data']['assignment_count'] = count($participants);
            $this->store->append($studyId, 0, 'allocation', 'allocation.completed', $study['data']['allocation'], $this->now());
            $this->savePhase($study, 'allocated', $actor);
        });
    }

    public function transition(int $actor, int $studyId, int $revision, string $next, string $reason = ''): void
    {
        $this->store->transaction(function () use ($actor, $studyId, $revision, $next, $reason): void {
            $study = $this->locked($actor, $studyId, $revision);
            $current = $study['status'];
            $allowed = ['baseline' => ['pretest'], 'pretest' => ['allocation_ready'], 'allocated' => ['practice'],
                'practice' => ['feedback'], 'feedback' => ['check'], 'check' => ['between_lessons'],
                'between_lessons' => ['practice', 'posttest'], 'posttest' => ['waiting_delay'],
                'waiting_delay' => ['delayed'], 'delayed' => ['completed'], 'completed' => ['locked'], 'locked' => ['archived'], 'cancelled' => ['archived']];
            if ($next === 'cancelled' && ! in_array($current, ['locked', 'archived', 'cancelled'], true)) {
                ResearchProtocol::text($reason, 2000);
            } elseif ($next === 'paused' && ! in_array($current, ['draft', 'locked', 'archived', 'paused', 'cancelled'], true)) {
                ResearchProtocol::text($reason, 2000);
                $study['data']['resume_phase'] = $current;
            } elseif ($current === 'paused' && $next === ($study['data']['resume_phase'] ?? '')) {
                ResearchProtocol::text($reason, 2000);
            } elseif (! in_array($next, $allowed[$current] ?? [], true)) {
                throw new DomainException('허용되지 않은 연구 단계 전환입니다.');
            }
            if ($next === 'practice' && $current !== 'paused') {
                if ($study['data']['lesson'] >= count($study['data']['protocol']['lessons'])) { throw new DomainException('계획한 차시를 모두 마쳤습니다.'); }
                ++$study['data']['lesson'];
            }
            if ($next === 'posttest' && $study['data']['lesson'] !== count($study['data']['protocol']['lessons'])) {
                throw new DomainException('계획한 차시를 마친 뒤 사후검사를 시작해 주세요.');
            }
            if ($next === 'waiting_delay') { $study['data']['posttest_closed_at'] = $this->now(); }
            if ($next === 'check' && $current === 'feedback') { $this->collectSubmissions($study); }
            if ($next === 'delayed' && $current !== 'paused') {
                $ready = (new DateTimeImmutable($study['data']['posttest_closed_at'], new DateTimeZone('UTC')))->modify('+' . $study['data']['protocol']['delay_days'] . ' days');
                if ($this->clock->now() < $ready) { throw new DomainException('계획한 지연검사 간격이 지나지 않았습니다.'); }
            }
            if ($next === 'locked') {
                foreach ($this->store->participants($studyId) as $participant) {
                    if ($participant['consent'] !== 'granted') { continue; }
                    foreach (['pretest', 'posttest', 'delayed'] as $assessment) {
                        if ($this->outcome($study, $participant, $assessment)['status'] === 'pending') { throw new DomainException('검사 채점 또는 결측 사유를 완료해 주세요.'); }
                    }
                }
            }
            if (in_array($next, ['archived', 'cancelled'], true)) {
                foreach ($this->store->participants($studyId) as $p) { $this->store->updateParticipant($p['id'], ['active_user_id' => null]); }
            }
            $this->savePhase($study, $next, $actor, $reason);
        });
    }

    public function learner(int $userId): ?array
    {
        $participant = $this->store->activeForUser($userId);
        if ($participant === null || $participant['consent'] !== 'granted') { return null; }
        $study = $this->requireStudy($participant['study_id']);
        $this->assertMember($participant);
        $phase = $study['status'];
        $lesson = $study['data']['protocol']['lessons'][max(0, $study['data']['lesson'] - 1)] ?? null;
        $assessment = $this->assessmentKey($study);
        $answer = $assessment !== null ? $this->answer($study, $participant, $assessment) : null;
        return ['title' => $study['title'], 'study_id' => $study['id'], 'revision' => $study['revision'],
            'phase' => $phase, 'lesson' => $study['data']['lesson'],
            'problems' => in_array($phase, ['practice', 'feedback'], true) ? ($lesson['problems'] ?? []) : [],
            'prompt' => $phase === 'check' ? ($lesson['check'] ?? '') : ($study['data']['protocol']['forms'][$phase] ?? ''),
            'assessment' => $assessment, 'submitted' => $answer !== null];
    }

    public function submitAnswer(int $actor, int $studyId, int $revision, string $code, string $explanation): void
    {
        $code = ResearchProtocol::text($code, 100000);
        $explanation = ResearchProtocol::text($explanation, 16000);
        $this->store->transaction(function () use ($actor, $studyId, $revision, $code, $explanation): void {
            $study = $this->requireStudy($studyId, true);
            $participant = $this->store->activeForUser($actor);
            if ($participant === null || $participant['study_id'] !== $studyId || $participant['consent'] !== 'granted') { throw new DomainException('현재 연구에 응시할 수 없습니다.'); }
            $this->assertMember($participant);
            $assessment = $this->assessmentKey($study);
            if ($assessment === null || $study['revision'] !== $revision) { throw new DomainException('현재 검사 단계를 확인해 주세요.'); }
            if ($this->answer($study, $participant, $assessment) !== null) { throw new DomainException('이미 최종 답안을 제출했습니다.'); }
            $data = ['assessment' => $assessment, 'blind_id' => $this->blindId($study, $participant, $assessment),
                'code' => $code, 'explanation' => $explanation, 'support' => 'ai_disabled', 'submitted_at' => $this->now()];
            $this->store->append($studyId, $participant['id'], 'answer:' . $participant['id'] . ':' . $assessment, 'answer.submitted', $data, $this->now());
        });
    }

    public function ratingTasks(int $actor): array
    {
        $tasks = [];
        foreach ($this->store->studies() as $study) {
            if (! in_array($actor, $study['data']['protocol']['raters'], true) || in_array($study['status'], ['draft', 'archived', 'cancelled'], true)) { continue; }
            $records = $this->store->records($study['id']);
            $participants = array_column($this->store->participants($study['id']), null, 'id');
            foreach ($records as $record) {
                if ($record['kind'] !== 'answer.submitted' || ($participants[$record['participant_id']]['consent'] ?? '') !== 'granted') { continue; }
                $data = $record['data'];
                $rated = false;
                foreach ($records as $rating) {
                    if ($rating['kind'] === 'answer.rated' && $rating['data']['blind_id'] === $data['blind_id'] && $rating['data']['rater_id'] === $actor) { $rated = true; }
                }
                if (! $rated && ! in_array($study['status'], ['locked', 'archived'], true)) {
                    $tasks[] = ['study_id' => $study['id'], 'blind_id' => $data['blind_id'], 'code' => $data['code'],
                        'explanation' => $data['explanation'], 'prompt' => $this->promptFor($study, $data['assessment'])];
                }
            }
        }
        usort($tasks, static fn (array $a, array $b): int => strcmp($a['blind_id'], $b['blind_id']));
        return $tasks;
    }

    public function rate(int $actor, int $studyId, string $blindId, array $scores): void
    {
        foreach (ResearchProtocol::RUBRIC as $key => $maximum) {
            if (! isset($scores[$key]) || ! is_numeric($scores[$key]) || ! is_finite((float) $scores[$key]) || $scores[$key] < 0 || $scores[$key] > $maximum) { throw new DomainException('준거별 배점 범위 안에서 점수를 입력해 주세요.'); }
            $scores[$key] = (float) $scores[$key];
        }
        $scores = array_intersect_key($scores, ResearchProtocol::RUBRIC);
        $this->store->transaction(function () use ($actor, $studyId, $blindId, $scores): void {
            $study = $this->requireStudy($studyId, true);
            if (! in_array($actor, $study['data']['protocol']['raters'], true) || in_array($study['status'], ['locked', 'archived', 'cancelled'], true)) { throw new DomainException('이 답안을 채점할 수 없습니다.'); }
            $participants = array_column($this->store->participants($studyId), null, 'id');
            $answer = null;
            foreach ($this->store->records($studyId) as $record) {
                if ($record['kind'] === 'answer.submitted' && $record['data']['blind_id'] === $blindId) { $answer = $record; }
            }
            if ($answer === null || ($participants[$answer['participant_id']]['consent'] ?? '') !== 'granted') { throw new DomainException('채점할 답안을 찾을 수 없습니다.'); }
            $this->store->append($studyId, $answer['participant_id'], 'rating:' . $blindId . ':' . $actor, 'answer.rated',
                ['blind_id' => $blindId, 'assessment' => $answer['data']['assessment'], 'rater_id' => $actor, 'scores' => $scores, 'total' => array_sum($scores)], $this->now());
        });
    }

    public function missing(int $actor, int $studyId, int $revision, int $participantId, string $assessment, string $reason): void
    {
        if (! in_array($reason, ['absent', 'system_failure', 'not_submitted', 'withdrawn'], true)) { throw new DomainException('결측 사유를 선택해 주세요.'); }
        $this->store->transaction(function () use ($actor, $studyId, $revision, $participantId, $assessment, $reason): void {
            $study = $this->locked($actor, $studyId, $revision);
            if (in_array($study['status'], ['locked', 'archived', 'cancelled'], true) || ! in_array($assessment, ['pretest', 'posttest', 'delayed'], true)) { throw new DomainException('결측을 기록할 수 없는 단계입니다.'); }
            $closed = false;
            $closingPhase = ['pretest' => 'allocation_ready', 'posttest' => 'waiting_delay', 'delayed' => 'completed'][$assessment];
            foreach ($this->store->records($studyId) as $record) {
                if ($record['kind'] === 'phase.changed' && $record['data']['from'] === $assessment && $record['data']['to'] === $closingPhase) { $closed = true; }
            }
            if (! $closed) { throw new DomainException('검사를 종료한 뒤 미응시 사유를 기록해 주세요.'); }
            $participants = array_column($this->store->participants($studyId), null, 'id');
            $p = $participants[$participantId] ?? null;
            if ($p === null || $this->answer($study, $p, $assessment) !== null) { throw new DomainException('답안이 있는 학생은 미응시로 처리하지 않습니다.'); }
            $this->store->append($studyId, $participantId, 'missing:' . $participantId . ':' . $assessment, 'assessment.missing', ['assessment' => $assessment, 'reason' => $reason, 'actor' => $actor], $this->now());
        });
    }

    public function withdraw(int $actor, int $studyId, int $revision, int $participantId, string $reason): void
    {
        ResearchProtocol::text($reason, 2000);
        $this->store->transaction(function () use ($actor, $studyId, $revision, $participantId, $reason): void {
            $study = $this->locked($actor, $studyId, $revision);
            $participants = array_column($this->store->participants($studyId), null, 'id');
            if (! isset($participants[$participantId])) { throw new DomainException('연구 대상자를 찾을 수 없습니다.'); }
            // Keep the assignment ledger. Future collection and research exports exclude withdrawn records.
            $this->store->updateParticipant($participantId, ['consent' => 'withdrawn', 'active_user_id' => null]);
            $this->store->append($studyId, $participantId, 'withdrawal:' . $participantId, 'consent.withdrawn', ['actor' => $actor, 'reason' => $reason], $this->now());
            $this->store->updateStudy($study, $revision);
        });
    }

    public function context(AiFeedbackSubmission $submission): ?array
    {
        $p = $this->store->activeForUser($submission->ownerUserId());
        if ($p === null) { return null; }
        $study = $this->requireStudy($p['study_id']);
        if (\CodingPanda\OjResearch\Domain\PilotProtocol::is($study)) {
            if ($this->pilot === null) { throw new DomainException('시범 연구 정책을 확인할 수 없습니다.'); }
            return $this->pilot->context($submission);
        }
        $this->assertMember($p);
        $this->assertEnabled();
        $phase = $study['status'];
        if (in_array($phase, ['draft', 'baseline', 'allocation_ready', 'allocated', 'between_lessons', 'waiting_delay', 'completed', 'locked'], true)) { return null; }
        if ($phase !== 'feedback' || $p['consent'] !== 'granted') { throw new DomainException('현재 활동에서는 AI 풀이 도움을 사용할 수 없습니다.'); }
        $lesson = $study['data']['protocol']['lessons'][$study['data']['lesson'] - 1];
        if (! in_array($submission->problemId(), $lesson['problems'], true) || $submission->submittedAt() < ($study['data']['practice_started_at'] ?? $study['data']['phase_started_at'])) {
            throw new DomainException('현재 차시에서 제출한 학습 문제에만 AI 도움을 사용할 수 있습니다.');
        }
        $baseline = $p['baseline'];
        $policy = $p['arm'] === 'A' && in_array($baseline['analysis_status'] ?? '', ['classified', 'reliable'], true) ? 'level_adaptive' : 'common';
        $context = ['study_id' => $study['id'], 'participant_id' => $p['id'], 'revision' => $study['revision'], 'lesson' => $study['data']['lesson'],
            'assigned_arm' => $p['arm'], 'policy' => $policy, 'baseline' => $baseline,
            'instructions' => $study['data']['protocol']['instructions'], 'routing' => $study['data']['routing'],
            'problem_hash' => $study['data']['problem_hashes'][$submission->problemId()],
            'protocol_hash' => $study['data']['protocol_hash']];
        $context['token'] = ResearchProtocol::hash($context);
        $this->assertFeedbackQuota($context, $submission->submissionId());
        return $context;
    }

    public function record(array $context, string $event, array $data): void
    {
        if (($context['protocol_type'] ?? '') === \CodingPanda\OjResearch\Domain\PilotProtocol::TYPE) {
            if ($this->pilot === null) { throw new DomainException('시범 연구 정책을 확인할 수 없습니다.'); }
            $this->pilot->record($context, $event, $data); return;
        }
        if (! in_array($event, ['generated', 'delivered'], true)) { throw new DomainException('연구 사건 종류가 올바르지 않습니다.'); }
        $this->store->transaction(function () use ($context, $event, $data): void {
            $study = $this->requireStudy($context['study_id'], true);
            if ($study['revision'] !== $context['revision'] || $study['status'] !== 'feedback') { throw new DomainException('연구 단계가 바뀌어 피드백을 전달하지 않습니다.'); }
            $participants = array_column($this->store->participants($study['id']), null, 'id');
            $p = $participants[$context['participant_id']] ?? null;
            if ($p === null || $p['consent'] !== 'granted') { throw new DomainException('연구자료 수집이 중단되었습니다.'); }
            $this->assertMember($p);
            if ($event === 'generated') { $this->assertFeedbackQuota($context, (int) $data['submission_id']); }
            $this->store->append($study['id'], $p['id'], $event . ':' . $context['token'] . ':' . $data['submission_id'] . ':' . $data['input_hash'], 'feedback.' . $event,
                ['context' => array_diff_key($context, array_flip(['baseline', 'instructions', 'routing'])), 'feedback' => $data], $this->now());
        });
    }

    public function assertAiAccess(int $actorUserId): void
    {
        $p = $this->store->activeForUser($actorUserId);
        if ($p === null) { return; }
        $study = $this->requireStudy($p['study_id']);
        if (in_array($study['status'], ['pretest', 'practice', 'feedback', 'check', 'posttest', 'delayed', 'paused'], true)) {
            throw new DomainException('현재 연구 활동에서는 별도의 AI 작업을 사용할 수 없습니다.');
        }
    }

    public function assertFeedbackQuota(array $context, int $submissionId): void
    {
        $seen = [];
        foreach ($this->store->records($context['study_id']) as $record) {
            if ($record['participant_id'] === $context['participant_id'] && $record['kind'] === 'feedback.generated'
                && $record['data']['context']['lesson'] === $context['lesson']) { $seen[$record['data']['feedback']['submission_id']] = true; }
        }
        if (! isset($seen[$submissionId]) && count($seen) >= 3) { throw new DomainException('이 차시의 피드백 요청 한도에 도달했습니다.'); }
    }

    public function feedbackSamples(int $actor, int $studyId): array
    {
        $this->managed($actor, $studyId);
        $people = array_column($this->store->participants($studyId), null, 'id');
        $samples = [];
        foreach ($this->store->records($studyId) as $record) {
            $p = $people[$record['participant_id']] ?? null;
            if ($record['kind'] !== 'feedback.generated' || $p === null || $p['consent'] !== 'granted') { continue; }
            $samples[] = ['participant' => $p['pseudonym'], 'arm' => $p['arm'], 'lesson' => $record['data']['context']['lesson'],
                'policy' => $record['data']['context']['policy'], 'at' => $record['created_at'], 'content' => $record['data']['feedback']['content'] ?? []];
        }
        return array_reverse(array_slice($samples, -20));
    }

    public function export(int $actor, int $studyId): array
    {
        $study = $this->managed($actor, $studyId);
        $participants = $this->store->participants($studyId);
        $rows = [];
        $map = [];
        $withdrawn = 0;
        foreach ($participants as $p) {
            if ($p['consent'] !== 'granted') { ++$withdrawn; continue; }
            $map[$p['id']] = $p['pseudonym'];
            $row = ['participant' => $p['pseudonym'], 'group' => 'G-' . (array_search($p['group_id'], $study['data']['protocol']['groups'], true) + 1),
                'assigned_arm' => $p['arm'], 'baseline_status' => $p['baseline']['analysis_status'], 'baseline_level' => $p['baseline']['level'],
                'baseline_skill_score' => $p['baseline']['skill_score'], 'baseline_policy_version' => $p['baseline']['policy_version']];
            foreach (['pretest', 'posttest', 'delayed'] as $assessment) {
                $outcome = $this->outcome($study, $p, $assessment);
                $row[$assessment . '_score'] = $outcome['score'];
                $row[$assessment . '_status'] = $outcome['status'];
                $row[$assessment . '_missing_reason'] = $outcome['reason'];
            }
            $rows[] = $row;
        }
        $events = [];
        foreach ($this->store->records($studyId) as $record) {
            if (! isset($map[$record['participant_id']])) { continue; }
            if (! in_array($record['kind'], ['answer.rated', 'feedback.generated', 'feedback.delivered', 'assessment.missing', 'submission.observed'], true)) { continue; }
            $data = $record['data'];
            if (isset($data['rater_id'])) { $data['rater'] = 'R' . (array_search($data['rater_id'], $study['data']['protocol']['raters'], true) + 1); }
            unset($data['actor'], $data['rater_id']);
            if (isset($data['context'])) { unset($data['context']['participant_id'], $data['context']['study_id']); }
            if (isset($data['feedback'])) { unset($data['feedback']['content']); }
            $events[] = ['participant' => $map[$record['participant_id']], 'kind' => $record['kind'], 'at' => $record['created_at'], 'data' => $data];
        }
        $protocol = $study['data']['protocol'];
        unset($protocol['groups'], $protocol['raters'], $protocol['consent_reference']);
        return ['schema' => 'research_export.v1', 'study_revision' => $study['revision'], 'generated_at' => $this->now(),
            'assignment_count' => $study['data']['assignment_count'] ?? 0,
            'withdrawn_count' => $withdrawn, 'erased_count' => $study['data']['erased_count'] ?? 0,
            'protocol' => $protocol, 'participants' => $rows, 'events' => $events,
            'data_hash' => ResearchProtocol::hash([$rows, $events]), 'missing_values' => 'null is missing; zero is an observed score'];
    }

    public function outcome(array $study, array $p, string $assessment): array
    {
        $answer = $this->answer($study, $p, $assessment);
        $ratings = [];
        $reason = null;
        foreach ($this->store->records($study['id']) as $record) {
            if ($record['participant_id'] !== $p['id']) { continue; }
            if ($record['kind'] === 'assessment.missing' && $record['data']['assessment'] === $assessment) { $reason = $record['data']['reason']; }
            if ($answer !== null && $record['kind'] === 'answer.rated' && $record['data']['blind_id'] === $answer['blind_id']) { $ratings[$record['data']['rater_id']] = $record['data']['total']; }
        }
        return ['score' => count($ratings) === 2 ? array_sum($ratings) / 2 : null,
            'status' => count($ratings) === 2 ? 'scored' : ($reason !== null ? 'missing' : 'pending'), 'reason' => $reason];
    }

    private function answer(array $study, array $p, string $assessment): ?array
    {
        foreach ($this->store->records($study['id']) as $record) {
            if ($record['participant_id'] === $p['id'] && $record['kind'] === 'answer.submitted' && $record['data']['assessment'] === $assessment) { return $record['data']; }
        }
        return null;
    }

    private function collectSubmissions(array $study): void
    {
        $lesson = $study['data']['protocol']['lessons'][$study['data']['lesson'] - 1];
        foreach ($this->store->participants($study['id']) as $p) {
            if ($p['consent'] !== 'granted') { continue; }
            foreach ($this->learning->submissions($p['user_id'], $lesson['problems'], $study['data']['practice_started_at'], $this->now()) as $submission) {
                $submission['lesson'] = $study['data']['lesson'];
                $this->store->append($study['id'], $p['id'], 'submission:' . $submission['submission_id'] . ':' . ResearchProtocol::hash($submission), 'submission.observed', $submission, $this->now());
            }
        }
    }

    private function blindId(array $study, array $p, string $assessment): string
    {
        return substr(hash_hmac('sha256', $p['id'] . ':' . $assessment, $study['data']['blind_secret']), 0, 24);
    }

    private function assessmentKey(array $study): ?string
    {
        return $study['status'] === 'check' ? 'check-' . $study['data']['lesson']
            : (in_array($study['status'], ['pretest', 'posttest', 'delayed'], true) ? $study['status'] : null);
    }

    private function promptFor(array $study, string $assessment): string
    {
        return str_starts_with($assessment, 'check-') ? $study['data']['protocol']['lessons'][(int) substr($assessment, 6) - 1]['check']
            : $study['data']['protocol']['forms'][$assessment];
    }

    private function assertManager(int $actor, array $study): void
    {
        if ($actor < 1 || ! $this->permissions->can($actor, 'oj_access_console') || in_array($actor, $study['data']['protocol']['raters'], true)) { throw new DomainException('연구 운영 권한이 없습니다.'); }
        foreach ($study['data']['protocol']['groups'] as $id) {
            $group = $this->groups->findById($id);
            if ($group === null || $group->isDeleted() || ! $group->isEnabled() || ! $this->groups->canManage($actor, $group)) { throw new DomainException('연구에 연결된 그룹 관리 권한이 필요합니다.'); }
        }
    }

    private function assertMember(array $participant): void
    {
        if (! $this->groups->isActiveMember($participant['group_id'], $participant['user_id'])) { throw new DomainException('현재 그룹 참여 권한이 없습니다.'); }
    }

    private function locked(int $actor, int $id, int $revision): array
    {
        $this->assertEnabled();
        $study = $this->requireStudy($id, true);
        $this->assertManager($actor, $study);
        if ($study['revision'] !== $revision) { throw new DomainException('연구가 변경되었습니다. 화면을 새로고침해 주세요.'); }
        return $study;
    }

    private function requireStudy(int $id, bool $lock = false): array
    {
        return $this->store->study($id, $lock) ?? throw new DomainException('연구를 찾을 수 없습니다.');
    }

    private function savePhase(array $study, string $phase, int $actor, string $reason = ''): void
    {
        $previous = $study['status'];
        $study['status'] = $phase;
        $study['data']['phase'] = $phase;
        $study['data']['phase_started_at'] = $this->now();
        if ($phase === 'practice' && $previous !== 'paused') { $study['data']['practice_started_at'] = $this->now(); }
        $study['updated_at'] = $this->now();
        $this->store->append($study['id'], 0, 'phase:' . ($study['revision'] + 1), 'phase.changed', ['actor' => $actor, 'from' => $previous, 'to' => $phase, 'reason' => $reason], $this->now());
        $this->store->updateStudy($study, $study['revision']);
    }

    private function assertEnabled(): void
    {
        if (! $this->features->isEnabled('research')) { throw new DomainException('수업 연구 기능이 꺼져 있습니다.'); }
    }

    private function now(): string { return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
}
