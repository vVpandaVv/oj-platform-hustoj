<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizBankRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use DomainException;

final class QuizBankService
{
    public function __construct(
        private readonly QuizBankRepository $bank,
        private readonly Authorization $permissions,
        private readonly QuizService $quizzes,
        private readonly QuizKeyFactory $keys
    ) {
    }

    /**
     * @return list<QuizBankQuestion>
     */
    public function list(int $userId, string $keyword): array
    {
        $this->assertManage($userId);

        return $this->bank->search($keyword);
    }

    public function find(int $userId, int $id): QuizBankQuestion
    {
        $this->assertManage($userId);
        $item = $this->bank->find($id);
        if ($item === null) {
            throw new DomainException('문항 은행 항목을 찾을 수 없습니다.');
        }

        return $item;
    }

    public function usageCount(int $userId, int $id): int
    {
        $this->assertManage($userId);

        return $this->bank->usageCount($id);
    }

    public function save(int $userId, ?int $id, QuizQuestion $question): QuizBankQuestion
    {
        $this->assertManage($userId);

        return $this->bank->save($id, $question, $userId);
    }

    public function delete(int $userId, int $id): void
    {
        $this->assertManage($userId);
        $this->bank->delete($id);
    }

    public function importIntoDraft(
        int $userId,
        ProblemId $problemId,
        int $bankId
    ): QuizRevision {
        $this->assertManage($userId);
        $bankItem = $this->bank->find($bankId);
        if ($bankItem === null) {
            throw new DomainException('문항 은행 항목을 찾을 수 없습니다.');
        }
        $context = $this->quizzes->managed($userId, $problemId);
        $base = $context['draft'] ?? $context['published'];
        if ($base === null) {
            throw new DomainException(
                '먼저 이 문제에 퀴즈 초안을 만든 뒤 문항을 가져올 수 있습니다.'
            );
        }
        $questions = $base->definition()->questions();
        $questions[] = $this->cloneForImport(
            $bankItem->question(),
            count($questions) + 1
        );
        $definition = new QuizDefinition(
            $base->definition()->title(),
            $base->definition()->description(),
            $base->definition()->attemptLimit(),
            $base->definition()->resultPolicy(),
            $base->definition()->resultsAvailableAt(),
            $base->definition()->publicStatistics(),
            $base->definition()->protectPublished(),
            $base->definition()->isEnabled(),
            $questions
        );

        return $this->quizzes->saveDraft($userId, $problemId, $definition);
    }

    public function exportToBank(
        int $userId,
        ProblemId $problemId,
        string $questionKey
    ): QuizBankQuestion {
        $this->assertManage($userId);
        $context = $this->quizzes->managed($userId, $problemId);
        $draft = $context['draft'];
        if ($draft === null) {
            throw new DomainException('내보낼 퀴즈 초안이 없습니다.');
        }
        $source = null;
        foreach ($draft->definition()->questions() as $question) {
            if ($question->key() === $questionKey) {
                $source = $question;
                break;
            }
        }
        if ($source === null) {
            throw new DomainException('내보낼 문항을 찾을 수 없습니다.');
        }

        return $this->bank->save(null, $source, $userId);
    }

    private function cloneForImport(QuizQuestion $source, int $sortOrder): QuizQuestion
    {
        return new QuizQuestion(
            0,
            $this->keys->uuid(),
            $source->title(),
            $source->body(),
            $source->type(),
            $source->explanation(),
            $source->points(),
            $sortOrder,
            $source->isEnabled(),
            array_map(
                fn (QuizChoice $choice): QuizChoice => new QuizChoice(
                    0,
                    $this->keys->uuid(),
                    $choice->label(),
                    $choice->body(),
                    $choice->isCorrect(),
                    $choice->sortOrder(),
                    $choice->isEnabled()
                ),
                $source->choices()
            ),
            $source->acceptedAnswers()
        );
    }

    private function assertManage(int $userId): void
    {
        if (! $this->permissions->can($userId, 'oj_manage_quizzes')) {
            throw new DomainException('문항 은행을 관리할 권한이 없습니다.');
        }
    }
}
