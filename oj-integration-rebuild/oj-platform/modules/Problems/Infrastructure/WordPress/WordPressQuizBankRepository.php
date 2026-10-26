<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizBankRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use DomainException;
use JsonException;
use RuntimeException;

final class WordPressQuizBankRepository implements QuizBankRepository
{
    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function find(int $id): ?QuizBankQuestion
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->database->row(
            'SELECT * FROM `quiz_question_bank` WHERE `id` = %d',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function search(string $keyword): array
    {
        $keyword = trim($keyword);
        $rows = $keyword === ''
            ? $this->database->rows(
                'SELECT * FROM `quiz_question_bank` ORDER BY `id` DESC LIMIT 200',
                []
            )
            : $this->database->rows(
                'SELECT * FROM `quiz_question_bank` WHERE `title` LIKE %s'
                    . ' ORDER BY `id` DESC LIMIT 200',
                ['%' . $keyword . '%']
            );

        return array_map(fn (array $row): QuizBankQuestion => $this->hydrate($row), $rows);
    }

    public function save(?int $id, QuizQuestion $question, int $actorUserId): QuizBankQuestion
    {
        if ($actorUserId < 1) {
            throw new DomainException('문항 은행 작성 사용자를 확인할 수 없습니다.');
        }

        return $this->database->transaction(
            function () use ($id, $question, $actorUserId): QuizBankQuestion {
                $now = gmdate('Y-m-d H:i:s');
                $columns = [
                    'question_key' => $question->key(),
                    'title' => $question->title(),
                    'body' => $question->body(),
                    'question_type' => $question->type(),
                    'correct_answer' => $this->encodeAcceptedAnswers(
                        $question->acceptedAnswers()
                    ),
                    'grading_rubric' => $question->explanation(),
                    'points' => $question->points(),
                    'enabled' => $question->isEnabled() ? 1 : 0,
                    'updated_at' => $now,
                ];
                if ($id === null) {
                    $columns['created_by_user_id'] = $actorUserId;
                    $columns['created_at'] = $now;
                    $bankId = $this->database->insert('quiz_question_bank', $columns);
                } else {
                    $existing = $this->database->row(
                        'SELECT `id` FROM `quiz_question_bank` WHERE `id` = %d FOR UPDATE',
                        [$id]
                    );
                    if ($existing === null) {
                        throw new DomainException('문항 은행 항목을 찾을 수 없습니다.');
                    }
                    $this->database->execute(
                        'UPDATE `quiz_question_bank` SET `question_key` = %s,'
                            . ' `title` = %s, `body` = %s, `question_type` = %s,'
                            . ' `correct_answer` = %s, `grading_rubric` = %s,'
                            . ' `points` = %f, `enabled` = %d, `updated_at` = %s'
                            . ' WHERE `id` = %d',
                        [
                            $columns['question_key'],
                            $columns['title'],
                            $columns['body'],
                            $columns['question_type'],
                            $columns['correct_answer'],
                            $columns['grading_rubric'],
                            $columns['points'],
                            $columns['enabled'],
                            $columns['updated_at'],
                            $id,
                        ]
                    );
                    $bankId = $id;
                    $this->database->execute(
                        'DELETE FROM `quiz_question_bank_choices`'
                            . ' WHERE `bank_question_id` = %d',
                        [$bankId]
                    );
                }
                foreach ($question->choices() as $choice) {
                    $this->database->insert('quiz_question_bank_choices', [
                        'bank_question_id' => $bankId,
                        'choice_key' => $choice->key(),
                        'label' => $choice->label(),
                        'body' => $choice->body(),
                        'is_correct' => $choice->isCorrect() ? 1 : 0,
                        'sort_order' => $choice->sortOrder(),
                        'enabled' => $choice->isEnabled() ? 1 : 0,
                        'updated_at' => $now,
                    ]);
                }
                $row = $this->database->row(
                    'SELECT * FROM `quiz_question_bank` WHERE `id` = %d',
                    [$bankId]
                );
                if ($row === null) {
                    throw new RuntimeException('문항 은행 항목을 저장하지 못했습니다.');
                }

                return $this->hydrate($row);
            }
        );
    }

    public function delete(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $this->database->execute(
            'UPDATE `quiz_questions` SET `bank_question_id` = NULL'
                . ' WHERE `bank_question_id` = %d',
            [$id]
        );
        $this->database->execute(
            'DELETE FROM `quiz_question_bank_choices` WHERE `bank_question_id` = %d',
            [$id]
        );
        $this->database->execute(
            'DELETE FROM `quiz_question_bank` WHERE `id` = %d',
            [$id]
        );
    }

    public function usageCount(int $id): int
    {
        if ($id < 1) {
            return 0;
        }
        $row = $this->database->row(
            'SELECT COUNT(*) AS `count` FROM `quiz_questions`'
                . ' WHERE `bank_question_id` = %d',
            [$id]
        );

        return (int) ($row['count'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): QuizBankQuestion
    {
        $bankId = (int) ($row['id'] ?? 0);
        $choiceRows = $this->database->rows(
            'SELECT * FROM `quiz_question_bank_choices`'
                . ' WHERE `bank_question_id` = %d ORDER BY `sort_order`',
            [$bankId]
        );
        $choices = array_map(
            static fn (array $choiceRow): QuizChoice => new QuizChoice(
                (int) ($choiceRow['id'] ?? 0),
                (string) ($choiceRow['choice_key'] ?? ''),
                (string) ($choiceRow['label'] ?? ''),
                (string) ($choiceRow['body'] ?? ''),
                (int) ($choiceRow['is_correct'] ?? 0) === 1,
                (int) ($choiceRow['sort_order'] ?? 0),
                (int) ($choiceRow['enabled'] ?? 0) === 1
            ),
            $choiceRows
        );
        $explanation = $row['grading_rubric'] ?? null;
        $question = new QuizQuestion(
            $bankId,
            (string) ($row['question_key'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['question_type'] ?? ''),
            is_string($explanation) && $explanation !== '' ? $explanation : null,
            (float) ($row['points'] ?? 0),
            1,
            (int) ($row['enabled'] ?? 0) === 1,
            $choices,
            $this->decodeAcceptedAnswers($row['correct_answer'] ?? null)
        );

        return new QuizBankQuestion(
            $bankId,
            $question,
            (int) ($row['created_by_user_id'] ?? 0),
            (string) ($row['created_at'] ?? '')
        );
    }

    /**
     * @param list<string> $acceptedAnswers
     */
    private function encodeAcceptedAnswers(array $acceptedAnswers): ?string
    {
        if ($acceptedAnswers === []) {
            return null;
        }
        try {
            return json_encode(
                $acceptedAnswers,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new RuntimeException('문항 은행 정답 목록을 저장할 수 없습니다.', 0, $error);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeAcceptedAnswers(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $answer): bool => is_string($answer)
        ));
    }
}
