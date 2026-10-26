<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use DomainException;
use JsonException;

final class ProblemAiOutputValidator
{
    public function __construct(
        private readonly ProblemTopicDictionary $topics
    ) {
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function metadata(array $output): array
    {
        $difficultyValue = $output['difficulty_score'] ?? null;
        if (is_string($difficultyValue) && preg_match('/^-?\d+$/D', $difficultyValue) === 1) {
            $difficultyValue = (int) $difficultyValue;
        }
        if (
            ! is_int($difficultyValue)
            || $difficultyValue < DifficultyScore::MIN
            || $difficultyValue > DifficultyScore::MAX
        ) {
            throw new DomainException('AI 난이도는 1부터 5까지의 정수여야 합니다.');
        }
        $difficulty = new DifficultyScore($difficultyValue);
        $topicKeys = $this->stringList($output['topic_keys'] ?? null, 0, 3);
        $this->assertMetadataTopics($topicKeys);
        if (! array_key_exists('confidence', $output)) {
            throw new DomainException('AI 신뢰도(confidence)가 누락되었습니다. 0~1 숫자가 필요합니다.');
        }
        $confidence = $this->metadataConfidence($output['confidence']);

        return [
            'difficulty_score' => $difficulty->value(),
            'topic_keys' => $topicKeys,
            'confidence' => $confidence,
            'rationale' => $this->requiredText(
                $output['rationale'] ?? null,
                2000,
                'AI 메타데이터 생성 근거'
            ),
        ];
    }

    private function metadataConfidence(mixed $value): float
    {
        $type = get_debug_type($value);
        if (is_string($value) && strlen($value) <= 64) {
            if (str_contains($value, '%')) {
                throw new DomainException('AI 신뢰도(confidence)에 백분율이 반환되었습니다. 0~1 숫자가 필요합니다.');
            }
            // Accept a quoted JSON number, not labels, percentages or guessed defaults.
            try {
                $decoded = json_decode(trim($value), true, 2, JSON_THROW_ON_ERROR);
                if (is_int($decoded) || is_float($decoded)) {
                    $value = $decoded;
                }
            } catch (JsonException) {
                // The type check below reports the invalid field without exposing AI text.
            }
        }
        if (! is_int($value) && ! is_float($value)) {
            $type = in_array($type, ['null', 'string', 'array', 'bool'], true) ? $type : 'object';
            throw new DomainException('AI 신뢰도(confidence)는 0~1 숫자여야 합니다. 반환 형식: ' . $type . '.');
        }
        if (! is_finite((float) $value) || $value < 0 || $value > 1) {
            throw new DomainException('AI 신뢰도(confidence)가 0~1 범위를 벗어났습니다. 자동 보정하지 않습니다.');
        }

        return (float) $value;
    }

    /** @param list<string> $keys */
    private function assertMetadataTopics(array $keys): void
    {
        $issues = [];
        foreach ($keys as $key) {
            try {
                $this->topics->assertSelectableDetails([$key]);
            } catch (DomainException) {
                $topic = $this->topics->find($key);
                $reason = match (true) {
                    $topic === null => '등록되지 않은 주제 키입니다. 표시 이름이 아닌 목록의 key를 사용해야 합니다.',
                    $topic->isCategory() => '상위 분야입니다. 하위의 세부 주제만 선택할 수 있습니다.',
                    ! $topic->isActive() => '비활성화된 세부 주제입니다.',
                    default => '상위 분야 연결이 올바르지 않아 선택할 수 없습니다.',
                };
                $issues[] = [
                    'key' => mb_strcut($key, 0, 160, 'UTF-8'),
                    'label' => mb_strcut($topic?->label() ?? '', 0, 160, 'UTF-8'),
                    'reason' => $reason,
                ];
            }
        }
        if ($issues !== []) {
            throw new ProblemAiTopicValidationException($issues);
        }
        $this->topics->assertSelectableDetails($keys);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, string>
     */
    public function authoring(array $output, string $mode): array
    {
        if (! in_array($mode, ['title_only', 'full_draft'], true)) {
            throw new DomainException('AI 문제 작성 방식이 올바르지 않습니다.');
        }
        $draft = [
            'title' => $this->text(
                $output['title'] ?? null,
                255,
                '문제 제목',
                false,
                true
            ),
        ];
        if ($mode === 'title_only') {
            return $draft;
        }
        $draft['description'] = $this->text(
            $output['description'] ?? null,
            1048576,
            '문제 설명'
        );
        $draft['input'] = $this->text(
            $output['input'] ?? null,
            262144,
            '입력 설명'
        );
        $draft['output'] = $this->text(
            $output['output'] ?? null,
            262144,
            '출력 설명'
        );
        $draft['sample_input'] = $this->text(
            $output['sample_input'] ?? null,
            262144,
            '예시 입력'
        );
        $draft['sample_output'] = $this->text(
            $output['sample_output'] ?? null,
            262144,
            '예시 출력'
        );
        $draft['hint'] = $this->text(
            $output['hint'] ?? null,
            262144,
            '힌트',
            true
        );

        return $draft;
    }

    /**
     * @param array<string, mixed> $output
     * @return array{files:list<array{name:string,contents:string}>,rationale:string}
     */
    public function testcases(array $output): array
    {
        $rawFiles = $output['files'] ?? null;
        if (! is_array($rawFiles) || ! array_is_list($rawFiles)) {
            throw new DomainException('AI 테스트케이스 파일 목록이 올바르지 않습니다.');
        }
        if (count($rawFiles) < 2 || count($rawFiles) > 40) {
            throw new DomainException('AI 테스트케이스는 파일 2개부터 40개까지 허용합니다.');
        }
        $files = [];
        $names = [];
        $totalBytes = 0;
        foreach ($rawFiles as $rawFile) {
            if (! is_array($rawFile)) {
                throw new DomainException('AI 테스트케이스 파일 형식이 올바르지 않습니다.');
            }
            $name = is_string($rawFile['name'] ?? null)
                ? trim($rawFile['name'])
                : '';
            $contents = is_string($rawFile['contents'] ?? null)
                ? $rawFile['contents']
                : '';
            if (
                preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.(?:in|out|ans)$/', $name) !== 1
                || isset($names[strtolower($name)])
                || preg_match('//u', $contents) !== 1
                || strlen($contents) > 8388608
            ) {
                throw new DomainException('AI 테스트케이스 파일 이름이나 내용이 올바르지 않습니다.');
            }
            $names[strtolower($name)] = true;
            $totalBytes += strlen($contents);
            if ($totalBytes > 16777216) {
                throw new DomainException('AI 테스트케이스 초안은 16MB를 초과할 수 없습니다.');
            }
            $files[] = ['name' => $name, 'contents' => $contents];
        }
        $bases = [];
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $base = substr($file['name'], 0, -(strlen($extension) + 1));
            $bases[$base][$extension] = true;
        }
        foreach ($bases as $extensions) {
            if (
                ! isset($extensions['in'])
                || (! isset($extensions['out']) && ! isset($extensions['ans']))
                || (isset($extensions['out']) && isset($extensions['ans']))
            ) {
                throw new DomainException('AI 테스트케이스의 입력·출력 파일 쌍이 올바르지 않습니다.');
            }
        }

        return [
            'files' => $files,
            'rationale' => $this->requiredText(
                $output['rationale'] ?? null,
                4000,
                'AI 테스트케이스 생성 근거'
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, int $minimum, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('AI 문자열 목록이 올바르지 않습니다.');
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new DomainException('AI 문자열 목록이 올바르지 않습니다.');
            }
            $items[] = trim($item);
        }
        if (
            count($items) < $minimum
            || count($items) > $maximum
            || count(array_unique($items)) !== count($items)
        ) {
            throw new DomainException('AI 문자열 목록의 개수나 중복이 올바르지 않습니다.');
        }

        return $items;
    }

    private function requiredText(
        mixed $value,
        int $maximumBytes,
        string $label
    ): string {
        return $this->text($value, $maximumBytes, $label, false, true);
    }

    private function text(
        mixed $value,
        int $maximumBytes,
        string $label,
        bool $allowEmpty = false,
        bool $trimResult = false
    ): string {
        if (
            ! is_string($value)
            || (! $allowEmpty && trim($value) === '')
            || preg_match('//u', $value) !== 1
            || strlen($value) > $maximumBytes
        ) {
            throw new DomainException($label . ' 형식이 올바르지 않습니다.');
        }

        return $trimResult ? trim($value) : $value;
    }
}
