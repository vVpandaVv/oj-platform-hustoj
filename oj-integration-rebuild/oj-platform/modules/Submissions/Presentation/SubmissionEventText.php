<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;

final class SubmissionEventText
{
    /**
     * @var array<string, string>
     */
    private const EVENT_LABELS = [
        'submission_requested' => '제출 요청 접수',
        'submission_prepared' => '제출 정보 준비',
        'submission_source_verified' => '제출 코드 검증',
        'submission_published' => '채점 대기열 등록',
        'submission_rejudge_requested' => '재채점 요청',
        'submission_invalidated' => '제출 무효 처리',
        'submission_restored' => '제출 복원',
        'submission_rejected' => '제출 요청 거부',
        'submission_recovery_required' => '제출 복구 확인 필요',
    ];

    /**
     * @var array<string, string>
     */
    private const EVENT_DESCRIPTIONS = [
        'submission_requested' => '제출 요청을 접수했습니다.',
        'submission_prepared' => '채점 전에 제출 정보를 준비했습니다.',
        'submission_source_verified' => '사용자가 작성한 코드와 실제 채점할 코드를 확인했습니다.',
        'submission_published' => '제출을 채점 대기열에 등록했습니다.',
        'submission_rejudge_requested' => '선택한 제출의 재채점을 요청했습니다.',
        'submission_invalidated' => '선택한 제출을 무효 처리했습니다.',
        'submission_restored' => '무효 처리된 제출을 복원했습니다.',
        'submission_rejected' => '제출 요청을 완료하지 못했습니다.',
        'submission_recovery_required' => '제출 상태를 운영자가 확인해야 합니다.',
    ];

    /**
     * @var list<string>
     */
    private const REASON_EVENT_TYPES = [
        'submission_rejudge_requested',
        'submission_invalidated',
        'submission_restored',
    ];

    /**
     * @var array<string, string>
     */
    private const METADATA_LABELS = [
        'problem_id' => '문제 번호',
        'submission_mode' => '작성 방식',
        'template_hash' => '템플릿 확인값',
        'ordering_block_count' => '코드 조각 수',
        'ordering_order' => '코드 조각 배열',
        'blockly_profile' => '블록 구성',
        'block_count' => '블록 수',
        'format' => '저장 형식',
        'generator_version' => '코드 생성기 버전',
        'policy_version' => '코드 정책 버전',
        'language_id' => '언어 번호',
        'contest_id' => '대회 번호',
        'contest_problem_number' => '대회 문제 순서',
        'class_id' => '수업 번호',
        'group_id' => '그룹 식별자',
        'previous_result' => '이전 채점 결과',
        'failure_kind' => '실패 구분',
        'changed' => '처리 결과',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const VALUE_LABELS = [
        'submission_mode' => [
            'code' => '코드',
            'code_completion' => '빈칸 완성',
            'code_ordering' => '코드 순서 배열',
            'blockly' => '블록',
        ],
        'blockly_profile' => [
            'intro' => '입문',
            'standard' => '표준',
            'extended' => '확장',
        ],
        'failure_kind' => [
            'policy' => '정책 검증',
            'system' => '시스템 처리',
        ],
        'changed' => [
            'true' => '변경됨',
            'false' => '변경 없음',
        ],
        'format' => [
            'blockly-json-v1' => 'Blockly 작업공간 1',
        ],
        'generator_version' => [
            'oj-python-v1' => 'Python 생성기 1',
        ],
    ];

    /**
     * @var list<string>
     */
    private const OPTIONAL_CONTEXT_KEYS = [
        'contest_id',
        'contest_problem_number',
        'class_id',
        'group_id',
    ];

    /**
     * @var list<string>
     */
    private const INTEGER_KEYS = [
        'problem_id',
        'block_count',
        'language_id',
        'contest_id',
        'contest_problem_number',
        'class_id',
        'previous_result',
    ];

    public function __construct(private readonly JudgeResults $results)
    {
    }

    public function eventLabel(string $eventType): string
    {
        return self::EVENT_LABELS[$eventType] ?? '기타 제출 처리';
    }

    public function eventDescription(
        string $eventType,
        string $storedMessage = ''
    ): string {
        $description = self::EVENT_DESCRIPTIONS[$eventType]
            ?? '제출 처리 내용을 확인했습니다.';
        $storedMessage = trim($storedMessage);
        if (
            $storedMessage !== ''
            && in_array($eventType, self::REASON_EVENT_TYPES, true)
        ) {
            return $description . ' 사유: ' . $storedMessage;
        }

        return $description;
    }

    public function metadataLabel(string $key): ?string
    {
        return self::METADATA_LABELS[$key] ?? null;
    }

    public function metadataValue(string $key, mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '없음';
        }
        if (
            in_array($key, self::OPTIONAL_CONTEXT_KEYS, true)
            && (string) $value === '0'
        ) {
            return '없음';
        }
        if ($key === 'previous_result' && is_numeric($value)) {
            return $this->results->find((int) $value)->label();
        }

        $raw = is_bool($value)
            ? ($value ? 'true' : 'false')
            : trim((string) $value);
        $label = self::VALUE_LABELS[$key][$raw] ?? null;
        if ($label !== null) {
            return $label;
        }
        if (is_bool($value)) {
            return $value ? '예' : '아니요';
        }
        if (in_array($key, self::INTEGER_KEYS, true) && is_numeric($value)) {
            return number_format((int) $value);
        }

        return $raw;
    }
}
