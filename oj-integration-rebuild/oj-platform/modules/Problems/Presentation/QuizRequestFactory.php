<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use DomainException;

final class QuizRequestFactory
{
    private const QUESTION_TYPES = ['single_choice', 'multi_choice', 'short_answer'];

    public function __construct(private readonly QuizKeyFactory $keys)
    {
    }

    public function definition(HttpRequest $request): QuizDefinition
    {
        $body = $request->body();
        $questionRows = $body['questions'] ?? [];
        if (! is_array($questionRows)) {
            throw new DomainException('퀴즈 문항 형식이 올바르지 않습니다.');
        }

        $questions = [];
        $sortOrder = 1;
        foreach (array_values($questionRows) as $questionRow) {
            if (! is_array($questionRow)) {
                continue;
            }
            $questions[] = $this->question($questionRow, $sortOrder);
            ++$sortOrder;
        }

        $resultPolicy = QuizResultPolicy::tryFrom(
            $request->bodyString('result_policy')
        );
        if ($resultPolicy === null) {
            throw new DomainException('퀴즈 결과 공개 정책이 올바르지 않습니다.');
        }

        return new QuizDefinition(
            trim($request->bodyString('title')),
            $request->bodyString('description'),
            $request->bodyInt('attempt_limit', 1),
            $resultPolicy,
            $this->resultsAvailableAt(
                $request->bodyString('results_available_at')
            ),
            $request->bodyBool('public_statistics'),
            $request->bodyBool('protect_published'),
            $request->bodyBool('enabled'),
            $questions
        );
    }

    public function bankQuestion(HttpRequest $request): QuizQuestion
    {
        $body = $request->body();
        $rows = $body['questions'] ?? [];
        if (! is_array($rows) || ! is_array($rows[0] ?? null)) {
            throw new DomainException('퀴즈 문항 형식이 올바르지 않습니다.');
        }

        return $this->question($rows[0], 1);
    }

    /**
     * @return array<int, int|list<int>|string>
     */
    public function answers(HttpRequest $request, QuizDefinition $definition): array
    {
        $rows = $request->body()['answers'] ?? [];
        if (! is_array($rows)) {
            throw new DomainException('퀴즈 답안 형식이 올바르지 않습니다.');
        }
        $types = [];
        foreach ($definition->questions() as $question) {
            $types[$question->id()] = $question->type();
        }
        $answers = [];
        foreach ($rows as $questionId => $value) {
            if (
                ! is_scalar($questionId)
                || preg_match('/^\d+$/', (string) $questionId) !== 1
            ) {
                throw new DomainException('퀴즈 답안 형식이 올바르지 않습니다.');
            }
            $qid = (int) $questionId;
            $answers[$qid] = match ($types[$qid] ?? null) {
                'multi_choice' => $this->answerChoiceIds($value),
                'short_answer' => $this->answerText($value),
                default => $this->answerChoiceId($value),
            };
        }

        return $answers;
    }

    private function answerChoiceId(mixed $value): int
    {
        if (! is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            throw new DomainException('퀴즈 답안 형식이 올바르지 않습니다.');
        }

        return (int) $value;
    }

    /**
     * @return list<int>
     */
    private function answerChoiceIds(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }
        $ids = [];
        foreach ($value as $item) {
            $ids[] = $this->answerChoiceId($item);
        }

        return array_values(array_unique($ids));
    }

    private function answerText(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new DomainException('퀴즈 답안 형식이 올바르지 않습니다.');
        }
        $text = (string) $value;
        if (strlen($text) > 1000 || preg_match('//u', $text) !== 1) {
            throw new DomainException('퀴즈 답안 형식이 올바르지 않습니다.');
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function question(array $row, int $sortOrder): QuizQuestion
    {
        $questionKey = $this->uuidOrNew($row['question_key'] ?? null);
        $type = $this->string($row['type'] ?? '');
        $type = in_array($type, self::QUESTION_TYPES, true)
            ? $type
            : 'single_choice';

        if ($type === 'short_answer') {
            return new QuizQuestion(
                0,
                $questionKey,
                trim($this->string($row['title'] ?? '')),
                $this->string($row['body'] ?? ''),
                $type,
                $this->nullableString($row['explanation'] ?? null),
                $this->float($row['points'] ?? 0),
                $sortOrder,
                $this->bool($row['enabled'] ?? '1'),
                [],
                $this->acceptedAnswers($row['accepted_answers_text'] ?? '')
            );
        }

        $choiceRows = $row['choices'] ?? [];
        if (! is_array($choiceRows)) {
            throw new DomainException('퀴즈 선택지 형식이 올바르지 않습니다.');
        }
        $normalizedRows = [];
        foreach (array_values($choiceRows) as $index => $choiceRow) {
            if (! is_array($choiceRow)) {
                continue;
            }
            $choiceRow['choice_key'] = $this->uuidOrNew(
                $choiceRow['choice_key'] ?? null
            );
            $choiceRow['index'] = $index;
            $normalizedRows[] = $choiceRow;
        }
        $correctTokens = [];
        if ($type === 'multi_choice') {
            foreach ((array) ($row['correct_choices'] ?? []) as $token) {
                if (is_scalar($token)) {
                    $correctTokens[(string) $token] = true;
                }
            }
        } else {
            $correctToken = is_scalar($row['correct_choice'] ?? null)
                ? (string) $row['correct_choice']
                : '';
            $correctTokens[$correctToken] = true;
        }
        $choices = [];
        foreach ($normalizedRows as $index => $choiceRow) {
            $key = (string) $choiceRow['choice_key'];
            $correct = isset($correctTokens[$key])
                || isset($correctTokens[(string) $index]);
            $choices[] = new QuizChoice(
                0,
                $key,
                trim($this->string($choiceRow['label'] ?? '')),
                $this->string($choiceRow['body'] ?? ''),
                $correct,
                $index + 1,
                $this->bool($choiceRow['enabled'] ?? '1')
            );
        }

        return new QuizQuestion(
            0,
            $questionKey,
            trim($this->string($row['title'] ?? '')),
            $this->string($row['body'] ?? ''),
            $type,
            $this->nullableString($row['explanation'] ?? null),
            $this->float($row['points'] ?? 0),
            $sortOrder,
            $this->bool($row['enabled'] ?? '1'),
            $choices
        );
    }

    /**
     * @return list<string>
     */
    private function acceptedAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }
        $answers = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $answers[] = $text;
            }
        }

        return array_values(array_unique($answers));
    }

    private function resultsAvailableAt(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        return $value;
    }

    private function uuidOrNew(mixed $value): string
    {
        $key = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if (
            preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $key
            ) === 1
        ) {
            return $key;
        }

        return $this->keys->uuid();
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return trim($value) === '' ? null : $value;
    }

    private function float(mixed $value): float
    {
        return is_scalar($value) && is_numeric((string) $value)
            ? (float) $value
            : 0.0;
    }

    private function bool(mixed $value): bool
    {
        return is_scalar($value)
            && in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}
