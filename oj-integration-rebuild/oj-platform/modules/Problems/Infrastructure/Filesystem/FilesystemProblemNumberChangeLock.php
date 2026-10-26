<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use JsonException;
use RuntimeException;

final class FilesystemProblemNumberChangeLock implements ProblemNumberChangeLock
{
    private const LOCK_FILE = '.oj-platform-problem-number-change.lock';
    private const STATE_FILE = '.oj-platform-problem-number-change.json';

    public function __construct(private readonly ProblemDataRootProvider $roots)
    {
    }

    public function withExclusiveLock(
        array $problemIds,
        callable $operation
    ): mixed {
        $ids = $this->normalizeIds($problemIds);
        $lockPath = $this->roots->root() . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $statePath = $this->roots->root() . DIRECTORY_SEPARATOR . self::STATE_FILE;
        $handle = fopen($lockPath, 'c');
        if (! is_resource($handle)) {
            throw new RuntimeException('문제 번호 변경 잠금을 만들지 못했습니다.');
        }
        $acquired = false;
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new DomainException('다른 문제 번호 변경 작업이 진행 중입니다.');
            }
            $acquired = true;
            $state = json_encode(
                [
                    'problem_ids' => $ids,
                    'started_at' => gmdate('c'),
                    'process_id' => getmypid(),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
            $temporary = $statePath . '.tmp-' . bin2hex(random_bytes(8));
            try {
                if (file_put_contents($temporary, $state, LOCK_EX) !== strlen($state)) {
                    throw new RuntimeException('문제 번호 변경 잠금 상태를 기록하지 못했습니다.');
                }
                if (! rename($temporary, $statePath)) {
                    throw new RuntimeException('문제 번호 변경 잠금 상태를 확정하지 못했습니다.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return $operation();
        } catch (JsonException $error) {
            throw new RuntimeException('문제 번호 변경 잠금 상태를 직렬화하지 못했습니다.', 0, $error);
        } finally {
            if ($acquired && is_file($statePath)) {
                unlink($statePath);
            }
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    public function isLocked(ProblemId $problemId): bool
    {
        return $this->lockedIdsContain($problemId->value());
    }

    public function hasActiveChange(): bool
    {
        return $this->lockedIdsContain(null);
    }

    private function lockedIdsContain(?int $problemId): bool
    {
        $root = $this->roots->root();
        $lockPath = $root . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $statePath = $root . DIRECTORY_SEPARATOR . self::STATE_FILE;
        $handle = fopen($lockPath, 'c');
        if (! is_resource($handle)) {
            return true;
        }
        try {
            if (flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);

                return false;
            }
            $contents = is_file($statePath)
                ? file_get_contents($statePath)
                : false;
            if (! is_string($contents)) {
                return true;
            }
            $state = json_decode($contents, true);
            if (! is_array($state) || ! is_array($state['problem_ids'] ?? null)) {
                return true;
            }

            return $problemId === null
                || in_array($problemId, $state['problem_ids'], true);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<int> $problemIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $problemIds): array
    {
        $ids = [];
        foreach ($problemIds as $problemId) {
            if (! is_int($problemId) || $problemId < 1) {
                throw new DomainException('잠글 문제 번호가 올바르지 않습니다.');
            }
            $ids[$problemId] = true;
        }
        $normalized = array_keys($ids);
        sort($normalized, SORT_NUMERIC);
        if ($normalized === [] || count($normalized) > 600) {
            throw new DomainException('잠글 문제 번호 집합이 올바르지 않습니다.');
        }

        return $normalized;
    }
}
