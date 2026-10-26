<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizGradingResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use DomainException;

final class QuizGrader
{
    /**
     * @param array<int, int|list<int>|string> $answers question_id => answer
     */
    public function grade(QuizRevision $revision, array $answers): QuizGradingResult
    {
        $questions = [];
        foreach ($revision->definition()->questions() as $question) {
            if ($question->isEnabled()) {
                $questions[$question->id()] = $question;
            }
        }
        foreach (array_keys($answers) as $questionId) {
            if (! isset($questions[$questionId])) {
                throw new DomainException('허용되지 않은 퀴즈 답안이 포함되어 있습니다.');
            }
        }

        $items = [];
        $score = 0.0;
        $maxScore = 0.0;
        foreach ($questions as $question) {
            $maxScore += $question->points();
            $item = match ($question->type()) {
                'multi_choice' => $this->gradeMultiChoice(
                    $question,
                    $answers[$question->id()] ?? null
                ),
                'short_answer' => $this->gradeShortAnswer(
                    $question,
                    $answers[$question->id()] ?? null
                ),
                default => $this->gradeSingleChoice(
                    $question,
                    $answers[$question->id()] ?? null
                ),
            };
            $score += $item->pointsAwarded();
            $items[] = $item;
        }

        return new QuizGradingResult($items, $score, $maxScore);
    }

    private function gradeSingleChoice(
        QuizQuestion $question,
        mixed $value
    ): QuizAttemptItem {
        $selected = is_int($value)
            ? $this->choiceById($question, $value)
            : null;
        $correct = $selected?->isCorrect() ?? false;
        $points = $correct ? $question->points() : 0.0;

        return new QuizAttemptItem(
            $question->id(),
            $selected?->id(),
            null,
            $correct,
            $points
        );
    }

    private function gradeMultiChoice(
        QuizQuestion $question,
        mixed $value
    ): QuizAttemptItem {
        $submitted = is_array($value) ? $value : [];
        $selectedIds = [];
        foreach ($submitted as $choiceId) {
            if (! is_int($choiceId)) {
                throw new DomainException('허용되지 않은 퀴즈 답안이 포함되어 있습니다.');
            }
            $selectedIds[] = $this->choiceById($question, $choiceId)->id();
        }
        $correctIds = array_map(
            static fn (QuizChoice $choice): int => $choice->id(),
            $question->correctChoices()
        );
        sort($selectedIds);
        sort($correctIds);
        $correct = $selectedIds === $correctIds;
        $points = $correct ? $question->points() : 0.0;

        return new QuizAttemptItem(
            $question->id(),
            null,
            null,
            $correct,
            $points,
            $selectedIds
        );
    }

    private function gradeShortAnswer(
        QuizQuestion $question,
        mixed $value
    ): QuizAttemptItem {
        $text = is_string($value) ? $value : '';
        $normalized = $this->normalize($text);
        $correct = false;
        if ($normalized !== '') {
            foreach ($question->acceptedAnswers() as $accepted) {
                if ($normalized === $this->normalize($accepted)) {
                    $correct = true;
                    break;
                }
            }
        }
        $points = $correct ? $question->points() : 0.0;

        return new QuizAttemptItem(
            $question->id(),
            null,
            $text === '' ? null : $text,
            $correct,
            $points
        );
    }

    private function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($collapsed ?? trim($value), 'UTF-8');
    }

    private function choiceById(QuizQuestion $question, int $choiceId): QuizChoice
    {
        foreach ($question->choices() as $choice) {
            if ($choice->id() === $choiceId && $choice->isEnabled()) {
                return $choice;
            }
        }

        throw new DomainException('문항에 속하지 않은 선택지가 포함되어 있습니다.');
    }
}
