<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizReapplyRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizReapplyMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use DomainException;

final class QuizReapplyService
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuizReapplyRepository $reapply,
        private readonly QuizAccessPolicy $access,
        private readonly QuizGrader $grader,
        private readonly JobDispatcher $jobs
    ) {
    }

    public function enqueue(
        int $actorUserId,
        QuizRevision $source,
        QuizRevision $target,
        bool $includeInProgress,
        bool $includeGraded
    ): ?JobEnqueueResult {
        $this->assertRevisionPair($source, $target);
        $this->assertCanRequest(
            $actorUserId,
            $target->problemId(),
            $target->definition(),
            $includeInProgress,
            $includeGraded
        );
        if (! $includeInProgress && ! $includeGraded) {
            return null;
        }

        $targets = [];
        if ($includeInProgress) {
            $targets = array_merge(
                $targets,
                $this->jobTargets(
                    $source,
                    $target,
                    QuizReapplyMode::InProgress
                )
            );
        }
        if ($includeGraded) {
            $targets = array_merge(
                $targets,
                $this->jobTargets(
                    $source,
                    $target,
                    QuizReapplyMode::Graded
                )
            );
        }
        if ($targets === []) {
            return null;
        }

        return $this->jobs->enqueue(
            'quiz_revision_reapply',
            'revision_reapply',
            $actorUserId,
            $targets,
            [
                'problem_id' => $target->problemId()->value(),
                'source_revision_id' => $source->id(),
                'target_revision_id' => $target->id(),
                'include_in_progress' => $includeInProgress,
                'include_graded' => $includeGraded,
            ],
            [
                'problem_id' => $target->problemId()->value(),
                'target_count' => count($targets),
            ],
            'problems.quiz_revision_reapply',
            '1',
            '1'
        );
    }

    public function assertCanRequest(
        int $actorUserId,
        ProblemId $problemId,
        QuizDefinition $targetDefinition,
        bool $includeInProgress,
        bool $includeGraded
    ): void {
        if (! $includeInProgress && ! $includeGraded) {
            return;
        }
        if (! $this->access->canManage($actorUserId, $problemId)) {
            throw new DomainException('이 퀴즈를 재적용할 권한이 없습니다.');
        }
        if ($targetDefinition->protectPublished()) {
            throw new DomainException('게시 버전 보호를 해제해야 기존 응시를 재적용할 수 있습니다.');
        }
        if (
            $includeGraded
            && ! $this->access->canRegradeCompleted($actorUserId)
        ) {
            throw new DomainException('완료 결과 재채점은 전역 퀴즈 관리자만 할 수 있습니다.');
        }
    }

    /**
     * @return array{mode:string, original_attempt_id:int, created_attempt_id:int}
     */
    public function execute(int $actorUserId, mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new DomainException('퀴즈 재적용 입력 형식이 올바르지 않습니다.');
        }
        $problemId = new ProblemId($this->positiveInt(
            $payload['problem_id'] ?? null,
            '문제 번호'
        ));
        $sourceRevisionId = $this->positiveInt(
            $payload['source_revision_id'] ?? null,
            '원본 버전'
        );
        $targetRevisionId = $this->positiveInt(
            $payload['target_revision_id'] ?? null,
            '대상 버전'
        );
        $originalAttemptId = $this->positiveInt(
            $payload['original_attempt_id'] ?? null,
            '원본 응시'
        );
        $mode = QuizReapplyMode::tryFrom(
            is_string($payload['mode'] ?? null)
                ? $payload['mode']
                : ''
        );
        $expectedAttemptKey = is_string($payload['original_attempt_key'] ?? null)
            ? $payload['original_attempt_key']
            : '';
        $sourceHash = is_string($payload['source_content_hash'] ?? null)
            ? $payload['source_content_hash']
            : '';
        $targetHash = is_string($payload['target_content_hash'] ?? null)
            ? $payload['target_content_hash']
            : '';
        if (
            $mode === null
            || preg_match('/^[a-f0-9]{64}$/', $expectedAttemptKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $targetHash) !== 1
        ) {
            throw new DomainException('퀴즈 재적용 대상 스냅샷이 올바르지 않습니다.');
        }

        $source = $this->quizzes->revision($sourceRevisionId);
        $target = $this->quizzes->revision($targetRevisionId);
        $original = $this->quizzes->attempt($originalAttemptId);
        if (
            $source === null
            || $target === null
            || $original === null
            || ! $source->problemId()->equals($problemId)
            || ! $target->problemId()->equals($problemId)
            || ! $original->problemId()->equals($problemId)
            || $original->revisionId() !== $source->id()
            || ! hash_equals($expectedAttemptKey, $original->attemptKey())
            || ! hash_equals($sourceHash, $source->contentHash())
            || ! hash_equals($targetHash, $target->contentHash())
        ) {
            throw new DomainException('퀴즈 재적용 대상이 등록 당시와 달라졌습니다.');
        }
        $this->assertRevisionPair($source, $target);
        if (! $this->access->canManage($actorUserId, $problemId)) {
            throw new DomainException('현재 권한으로 퀴즈를 재적용할 수 없습니다.');
        }

        $attemptKey = hash(
            'sha256',
            'quiz-reapply:' . $mode->value . ':' . $original->id()
                . ':' . $target->id()
        );
        if ($mode === QuizReapplyMode::InProgress) {
            if (! in_array(
                $original->status(),
                ['in_progress', 'superseded'],
                true
            )) {
                throw new DomainException('진행 중 재적용 대상의 상태가 변경되었습니다.');
            }
            $created = $this->reapply->replaceInProgress(
                $original,
                $target,
                $attemptKey
            );
        } else {
            if (! $this->access->canRegradeCompleted($actorUserId)) {
                throw new DomainException('완료 결과 재채점 권한이 변경되었습니다.');
            }
            if ($original->status() !== 'graded') {
                throw new DomainException('완료 결과 재채점 대상의 상태가 변경되었습니다.');
            }
            $answers = $this->mapAnswers($source, $target, $original);
            $grading = $this->grader->grade($target, $answers);
            $created = $this->reapply->createRegrade(
                $original,
                $target,
                $attemptKey,
                $grading->items(),
                $grading->score(),
                $grading->maxScore()
            );
        }

        return [
            'mode' => $mode->value,
            'original_attempt_id' => $original->id(),
            'created_attempt_id' => $created->id(),
        ];
    }

    /**
     * @return list<JobTarget>
     */
    private function jobTargets(
        QuizRevision $source,
        QuizRevision $target,
        QuizReapplyMode $mode
    ): array {
        $targets = [];
        foreach ($this->reapply->candidates(
            $source->problemId(),
            $source->id(),
            $mode
        ) as $attempt) {
            $targets[] = new JobTarget(
                'quiz_attempt',
                $mode->value . ':' . $attempt->id() . ':' . $target->id(),
                [
                    'problem_id' => $source->problemId()->value(),
                    'source_revision_id' => $source->id(),
                    'source_content_hash' => $source->contentHash(),
                    'target_revision_id' => $target->id(),
                    'target_content_hash' => $target->contentHash(),
                    'original_attempt_id' => $attempt->id(),
                    'original_attempt_key' => $attempt->attemptKey(),
                    'mode' => $mode->value,
                ],
                $target->contentHash()
            );
        }

        return $targets;
    }

    /**
     * @return array<int, int>
     */
    private function mapAnswers(
        QuizRevision $source,
        QuizRevision $target,
        QuizAttempt $attempt
    ): array {
        $sourceQuestions = [];
        foreach ($source->definition()->questions() as $question) {
            if ($question->isEnabled()) {
                $sourceQuestions[$question->id()] = $question;
            }
        }
        $targetQuestions = [];
        foreach ($target->definition()->questions() as $question) {
            if ($question->isEnabled()) {
                $targetQuestions[$question->key()] = $question;
            }
        }
        if (
            count($sourceQuestions) !== count($targetQuestions)
            || count($attempt->items()) !== count($sourceQuestions)
        ) {
            throw new DomainException(
                '문항 구성이 달라 완료 결과를 안전하게 재채점할 수 없습니다.'
            );
        }

        $answers = [];
        foreach ($attempt->items() as $item) {
            $sourceQuestion = $sourceQuestions[$item->questionId()] ?? null;
            if (! $sourceQuestion instanceof QuizQuestion) {
                throw new DomainException('원본 응시 문항의 안정 키를 찾을 수 없습니다.');
            }
            $targetQuestion = $targetQuestions[$sourceQuestion->key()] ?? null;
            if (! $targetQuestion instanceof QuizQuestion) {
                throw new DomainException('문항 안정 키가 새 버전과 대응하지 않습니다.');
            }
            if ($item->choiceId() === null) {
                continue;
            }
            $sourceChoice = $this->choiceById(
                $sourceQuestion,
                $item->choiceId()
            );
            if ($sourceChoice === null) {
                throw new DomainException('원본 선택지를 찾을 수 없습니다.');
            }
            $targetChoice = $this->choiceByKey(
                $targetQuestion,
                $sourceChoice->key()
            );
            if ($targetChoice === null) {
                throw new DomainException('선택지 안정 키가 새 버전과 대응하지 않습니다.');
            }
            $answers[$targetQuestion->id()] = $targetChoice->id();
        }

        return $answers;
    }

    private function choiceById(
        QuizQuestion $question,
        ?int $choiceId
    ): ?QuizChoice {
        if ($choiceId === null) {
            return null;
        }
        foreach ($question->choices() as $choice) {
            if ($choice->isEnabled() && $choice->id() === $choiceId) {
                return $choice;
            }
        }

        return null;
    }

    private function choiceByKey(
        QuizQuestion $question,
        string $choiceKey
    ): ?QuizChoice {
        foreach ($question->choices() as $choice) {
            if ($choice->isEnabled() && hash_equals($choice->key(), $choiceKey)) {
                return $choice;
            }
        }

        return null;
    }

    private function assertRevisionPair(
        QuizRevision $source,
        QuizRevision $target
    ): void {
        if (
            ! $source->problemId()->equals($target->problemId())
            || $source->status() !== 'published'
            || $target->status() !== 'published'
            || ! $target->isCurrentPublished()
            || $source->id() === $target->id()
            || $target->definition()->protectPublished()
        ) {
            throw new DomainException('재적용할 퀴즈 게시 버전을 확인해 주세요.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($integer === false) {
            throw new DomainException($label . '가 올바르지 않습니다.');
        }

        return (int) $integer;
    }
}
