<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use JsonException;
use RuntimeException;

final class WordPressJobResultJournal implements JobResultJournal
{
    private const STEP_KEY = 'worker.result';
    private const MAX_CHECKPOINT_BYTES = 16384;

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function find(ClaimedJobItem $claim): ?JobHandlerResult
    {
        $this->assertDatabase();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT input_hash, checkpoint
            FROM background_job_steps
            WHERE job_id = %d AND item_id = %d AND step_key = %s
              AND status = 'completed'",
            $claim->jobId(),
            $claim->itemId(),
            self::STEP_KEY
        ));
        if (! is_object($row)) {
            return null;
        }
        if (! hash_equals(
            $claim->inputHash(),
            (string) ($row->input_hash ?? '')
        )) {
            throw new RuntimeException('Jobs 성공 결과의 입력 해시가 일치하지 않습니다.');
        }
        $checkpoint = $this->decode($row->checkpoint ?? null);
        $result = JobHandlerResult::fromArray($checkpoint);
        if ($result->outcome() !== 'succeeded') {
            throw new RuntimeException('Jobs 성공 결과 체크포인트가 올바르지 않습니다.');
        }

        return $result;
    }

    public function save(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void {
        if ($result->outcome() !== 'succeeded') {
            throw new RuntimeException('성공한 Jobs 결과만 체크포인트로 저장할 수 있습니다.');
        }
        $this->assertDatabase();
        $checkpoint = $this->encode($result->toArray());
        $sql = $this->database->prepare(
            "INSERT INTO background_job_steps
                (job_id, item_id, step_key, step_order, definition_version,
                 status, attempt_count, input_hash, checkpoint,
                 started_at, completed_at, updated_at)
            VALUES (%d, %d, %s, 255, '1', 'completed', %d, %s, %s,
                    UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                status = IF(input_hash = VALUES(input_hash), 'completed', status),
                attempt_count = IF(
                    input_hash = VALUES(input_hash),
                    GREATEST(attempt_count, VALUES(attempt_count)),
                    attempt_count
                ),
                checkpoint = IF(
                    input_hash = VALUES(input_hash),
                    VALUES(checkpoint),
                    checkpoint
                ),
                completed_at = IF(
                    input_hash = VALUES(input_hash),
                    UTC_TIMESTAMP(),
                    completed_at
                ),
                updated_at = UTC_TIMESTAMP()",
            $claim->jobId(),
            $claim->itemId(),
            self::STEP_KEY,
            $claim->attempt(),
            $claim->inputHash(),
            $checkpoint
        );
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('Jobs 성공 결과 체크포인트를 저장하지 못했습니다.');
        }
        if ($this->find($claim) === null) {
            throw new RuntimeException('Jobs 성공 결과 체크포인트를 확인하지 못했습니다.');
        }
    }

    private function assertDatabase(): void
    {
        foreach (['prepare', 'query', 'get_row'] as $method) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress Jobs 결과 저장소를 사용할 수 없습니다.');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Jobs 성공 결과를 직렬화하지 못했습니다.',
                0,
                $error
            );
        }
        if (strlen($encoded) > self::MAX_CHECKPOINT_BYTES) {
            throw new RuntimeException('Jobs 성공 결과가 허용 크기를 초과했습니다.');
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (
            ! is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_CHECKPOINT_BYTES
        ) {
            throw new RuntimeException('Jobs 성공 결과 체크포인트가 올바르지 않습니다.');
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Jobs 성공 결과 체크포인트를 해석하지 못했습니다.',
                0,
                $error
            );
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Jobs 성공 결과 체크포인트가 올바르지 않습니다.');
        }

        return $decoded;
    }
}
