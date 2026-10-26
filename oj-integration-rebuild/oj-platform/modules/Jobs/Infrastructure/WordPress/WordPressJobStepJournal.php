<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\RecoverableJobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use JsonException;
use RuntimeException;

final class WordPressJobStepJournal implements
    JobStepJournal,
    RecoverableJobStepJournal
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function start(
        ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        string $inputHash,
        array $checkpoint = []
    ): void {
        $this->assertDatabase();
        $this->assertStep($stepKey, $stepOrder, $inputHash);
        $sql = $this->database->prepare(
            "INSERT INTO background_job_steps
                (job_id, item_id, step_key, step_order, definition_version,
                 status, attempt_count, input_hash, checkpoint, started_at, updated_at)
            VALUES (%d, %d, %s, %d, '1', 'running', 1, %s, %s, UTC_TIMESTAMP(),
                    UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                status = 'running',
                attempt_count = attempt_count + 1,
                input_hash = VALUES(input_hash),
                checkpoint = VALUES(checkpoint),
                started_at = UTC_TIMESTAMP(),
                completed_at = NULL,
                updated_at = UTC_TIMESTAMP()",
            $claim->jobId(),
            $claim->itemId(),
            $stepKey,
            $stepOrder,
            $inputHash,
            $this->json($checkpoint)
        );
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('Jobs 단계 시작 기록을 저장하지 못했습니다.');
        }
    }

    public function complete(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        $this->assertDatabase();
        $this->assertStepKey($stepKey);
        $sql = $this->database->prepare(
            "UPDATE background_job_steps
            SET status = 'completed',
                validation_result = %s,
                completed_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE job_id = %d AND item_id = %d AND step_key = %s
              AND status = 'running'",
            $this->json($validationResult),
            $claim->jobId(),
            $claim->itemId(),
            $stepKey
        );
        if ((int) $this->database->query($sql) !== 1) {
            throw new RuntimeException('Jobs 단계 완료 기록을 저장하지 못했습니다.');
        }
    }

    public function completedCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        string $inputHash
    ): ?array {
        $this->assertDatabase();
        $this->assertStepKey($stepKey);
        $this->assertInputHash($inputHash);
        if (! method_exists($this->database, 'get_row')) {
            throw new RuntimeException('Jobs 단계 조회 저장소를 사용할 수 없습니다.');
        }

        $row = $this->database->get_row($this->database->prepare(
            'SELECT status, input_hash, checkpoint
             FROM background_job_steps
             WHERE job_id = %d AND item_id = %d AND step_key = %s
             LIMIT 1',
            $claim->jobId(),
            $claim->itemId(),
            $stepKey
        ), 'ARRAY_A');
        if (
            ! is_array($row)
            || (string) ($row['status'] ?? '') !== 'completed'
            || ! hash_equals($inputHash, (string) ($row['input_hash'] ?? ''))
        ) {
            return null;
        }

        return $this->decode((string) ($row['checkpoint'] ?? ''));
    }

    public function completeWithCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        array $checkpoint,
        array $validationResult = []
    ): void {
        $this->assertDatabase();
        $this->assertStepKey($stepKey);
        $sql = $this->database->prepare(
            "UPDATE background_job_steps
            SET status = 'completed',
                checkpoint = %s,
                validation_result = %s,
                completed_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE job_id = %d AND item_id = %d AND step_key = %s
              AND status = 'running'",
            $this->json($checkpoint),
            $this->json($validationResult),
            $claim->jobId(),
            $claim->itemId(),
            $stepKey
        );
        if ((int) $this->database->query($sql) !== 1) {
            throw new RuntimeException('Jobs 단계 체크포인트를 저장하지 못했습니다.');
        }
    }

    public function compensate(
        ClaimedJobItem $claim,
        string $stepKey,
        bool $succeeded,
        array $compensationResult = []
    ): void {
        $this->assertDatabase();
        $this->assertStepKey($stepKey);
        $sql = $this->database->prepare(
            "UPDATE background_job_steps
            SET status = %s,
                compensation_result = %s,
                completed_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE job_id = %d AND item_id = %d AND step_key = %s",
            $succeeded ? 'compensated' : 'compensation_failed',
            $this->json($compensationResult),
            $claim->jobId(),
            $claim->itemId(),
            $stepKey
        );
        if ((int) $this->database->query($sql) !== 1) {
            throw new RuntimeException('Jobs 단계 보상 기록을 저장하지 못했습니다.');
        }
    }

    public function fail(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        $this->assertDatabase();
        $this->assertStepKey($stepKey);
        $sql = $this->database->prepare(
            "UPDATE background_job_steps
            SET status = 'failed',
                validation_result = %s,
                completed_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE job_id = %d AND item_id = %d AND step_key = %s
              AND status = 'running'",
            $this->json($validationResult),
            $claim->jobId(),
            $claim->itemId(),
            $stepKey
        );
        if ((int) $this->database->query($sql) !== 1) {
            throw new RuntimeException('Jobs 단계 실패 기록을 저장하지 못했습니다.');
        }
    }

    private function assertDatabase(): void
    {
        foreach (['prepare', 'query'] as $method) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress Jobs 단계 저장소를 사용할 수 없습니다.');
            }
        }
    }

    private function assertStep(
        string $stepKey,
        int $stepOrder,
        string $inputHash
    ): void {
        $this->assertStepKey($stepKey);
        if (
            $stepOrder < 1
            || $stepOrder > 255
            || ! $this->isInputHash($inputHash)
        ) {
            throw new RuntimeException('Jobs 단계 순서 또는 입력 해시가 올바르지 않습니다.');
        }
    }

    private function assertInputHash(string $inputHash): void
    {
        if (! $this->isInputHash($inputHash)) {
            throw new RuntimeException('Jobs 단계 입력 해시가 올바르지 않습니다.');
        }
    }

    private function isInputHash(string $inputHash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $inputHash) === 1;
    }

    private function assertStepKey(string $stepKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $stepKey) !== 1) {
            throw new RuntimeException('Jobs 단계 키가 올바르지 않습니다.');
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        if ($value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Jobs 단계 체크포인트를 읽지 못했습니다.',
                0,
                $error
            );
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Jobs 단계 체크포인트 형식이 올바르지 않습니다.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Jobs 단계 기록을 직렬화하지 못했습니다.', 0, $error);
        }
        if (strlen($json) > 16384) {
            throw new RuntimeException('Jobs 단계 기록 크기가 제한을 초과했습니다.');
        }

        return $json;
    }
}
