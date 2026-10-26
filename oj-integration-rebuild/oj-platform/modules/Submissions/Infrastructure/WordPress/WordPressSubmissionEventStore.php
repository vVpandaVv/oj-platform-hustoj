<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionReservation;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class WordPressSubmissionEventStore implements SubmissionEventStore
{
    private const TABLE = 'submission_events';

    private const FORBIDDEN_METADATA_KEYS = [
        'source',
        'code',
        'workspace',
        'answer',
        'compile',
        'runtime',
        'error',
        'secret',
        'password',
        'token',
        'prompt',
    ];

    public function __construct(
        private readonly SubmissionPlatformDatabase $database
    ) {
    }

    public function reserve(
        string $requestKey,
        int $actorUserId,
        array $metadata
    ): SubmissionReservation {
        $this->assertRequestKey($requestKey);
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('제출 실행자 번호가 올바르지 않습니다.');
        }
        $existing = $this->findByRequestKey($requestKey);
        if ($existing !== null) {
            return $this->reservation($existing, false, $actorUserId);
        }
        try {
            $eventId = $this->database->insert(self::TABLE, [
                'submission_id' => null,
                'event_type' => 'submission_requested',
                'actor_user_id' => $actorUserId,
                'request_key' => $requestKey,
                'message' => '제출 요청을 처리하고 있습니다.',
                'metadata' => $this->metadata($metadata),
            ]);
            if ($eventId < 1) {
                throw new RuntimeException('제출 요청 예약 번호를 확인하지 못했습니다.');
            }

            return new SubmissionReservation($eventId, true, null, false);
        } catch (Throwable $error) {
            $existing = $this->findByRequestKey($requestKey);
            if ($existing !== null) {
                return $this->reservation($existing, false, $actorUserId);
            }

            throw $error;
        }
    }

    public function complete(
        int $eventId,
        SubmissionId $submissionId,
        array $metadata
    ): void {
        $updated = $this->database->update(
            self::TABLE,
            [
                'submission_id' => $submissionId->value(),
                'event_type' => 'submission_published',
                'message' => '제출을 채점 대기 상태로 공개했습니다.',
                'metadata' => $this->metadata($metadata),
            ],
            [
                'id' => $eventId,
                'event_type' => 'submission_requested',
            ]
        );
        if ($updated !== 1) {
            throw new RuntimeException(
                '제출 요청 완료 이벤트를 갱신하지 못했습니다.'
            );
        }
    }

    public function fail(
        int $eventId,
        string $eventType,
        string $message,
        array $metadata
    ): void {
        $this->assertEventType($eventType);
        $updated = $this->database->update(
            self::TABLE,
            [
                'event_type' => $eventType,
                'message' => $this->message($message),
                'metadata' => $this->metadata($metadata),
            ],
            [
                'id' => $eventId,
                'event_type' => 'submission_requested',
            ]
        );
        if ($updated !== 1) {
            throw new RuntimeException(
                '제출 요청 실패 이벤트를 갱신하지 못했습니다.'
            );
        }
    }

    public function record(
        string $eventType,
        int $actorUserId,
        ?SubmissionId $submissionId,
        ?string $requestKey,
        string $message,
        array $metadata = []
    ): void {
        $this->assertEventType($eventType);
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('제출 처리 실행자 번호가 올바르지 않습니다.');
        }
        if ($requestKey !== null) {
            $this->assertRequestKey($requestKey);
            if ($this->findByRequestKey($requestKey) !== null) {
                return;
            }
        }
        try {
            $this->database->insert(self::TABLE, [
                'submission_id' => $submissionId?->value(),
                'event_type' => $eventType,
                'actor_user_id' => $actorUserId,
                'request_key' => $requestKey,
                'message' => $this->message($message),
                'metadata' => $this->metadata($metadata),
            ]);
        } catch (Throwable $error) {
            if (
                $requestKey !== null
                && $this->findByRequestKey($requestKey) !== null
            ) {
                return;
            }

            throw $error;
        }
    }

    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->rows(
            'SELECT `id`, `submission_id`, `event_type`, `actor_user_id`,'
                . ' `request_key`, `message`, `metadata`, `created_at`'
                . ' FROM `' . self::TABLE . '`'
                . ' ORDER BY `id` DESC LIMIT %d',
            [$limit]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByRequestKey(string $requestKey): ?array
    {
        return $this->database->row(
            'SELECT `id`, `submission_id`, `event_type`, `actor_user_id`'
                . ' FROM `' . self::TABLE . '`'
                . ' WHERE `request_key` = %s LIMIT 1',
            [$requestKey]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reservation(
        array $row,
        bool $new,
        int $actorUserId
    ): SubmissionReservation {
        if ((int) ($row['actor_user_id'] ?? 0) !== $actorUserId) {
            throw new DomainException('다른 사용자의 제출 요청 키를 사용할 수 없습니다.');
        }
        $submissionId = (int) ($row['submission_id'] ?? 0);
        $eventType = (string) ($row['event_type'] ?? '');

        return new SubmissionReservation(
            (int) ($row['id'] ?? 0),
            $new,
            $submissionId > 0 ? new SubmissionId($submissionId) : null,
            in_array(
                $eventType,
                ['submission_rejected', 'submission_recovery_required'],
                true
            )
        );
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function metadata(array $metadata): string
    {
        foreach ($metadata as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach (self::FORBIDDEN_METADATA_KEYS as $forbidden) {
                if (str_contains($normalized, $forbidden)) {
                    throw new InvalidArgumentException(
                        '제출 이벤트에 민감한 메타데이터를 저장할 수 없습니다.'
                    );
                }
            }
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(
                    '제출 이벤트 메타데이터는 작은 스칼라 값만 저장할 수 있습니다.'
                );
            }
        }
        ksort($metadata, SORT_STRING);
        try {
            $encoded = json_encode(
                $metadata,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                '제출 이벤트 메타데이터를 인코딩하지 못했습니다.',
                0,
                $error
            );
        }
        if (strlen($encoded) > 16384) {
            throw new InvalidArgumentException(
                '제출 이벤트 메타데이터가 최대 크기를 초과했습니다.'
            );
        }

        return $encoded;
    }

    private function assertRequestKey(string $requestKey): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $requestKey) !== 1) {
            throw new InvalidArgumentException('제출 요청 식별자가 올바르지 않습니다.');
        }
    }

    private function assertEventType(string $eventType): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,99}$/', $eventType) !== 1) {
            throw new InvalidArgumentException('제출 이벤트 종류가 올바르지 않습니다.');
        }
    }

    private function message(string $message): string
    {
        $message = trim($message);
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 500, 'UTF-8');
        }

        return substr($message, 0, 500);
    }
}
