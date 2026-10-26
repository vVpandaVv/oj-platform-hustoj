<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackValidationStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use DomainException;
use RuntimeException;

final class PrivateFeedbackValidationStore implements FeedbackValidationStore
{
    public function __construct(private readonly PrivateDataRootProvider $roots)
    {
    }

    public function reserve(array $record): bool
    {
        $path = $this->path($record['candidate_key'], $record['id']);
        $lock = fopen(dirname($path) . '/reserve.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('검증 저장소 잠금 실패');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('검증 저장소 잠금 실패');
            }
            if (is_file($path)) {
                return false;
            }
            if ($this->count($record['candidate_key']) >= 200) {
                throw new DomainException('지침별 검증 기록 한도 200건에 도달했습니다. 새 개정 지침을 만들어 주세요.');
            }
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('검증 기록 예약 실패');
            }
            try {
                $this->write($handle, $record);
            } finally {
                fclose($handle);
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function finish(array $record): void
    {
        $handle = fopen($this->path($record['candidate_key'], $record['id']), 'r+');
        if ($handle === false) {
            throw new RuntimeException('검증 기록을 찾을 수 없습니다.');
        }
        try {
            $this->write($handle, $record);
        } finally {
            fclose($handle);
        }
    }

    public function records(string $candidate): array
    {
        $records = [];
        foreach ($this->files($candidate) as $path) {
            $record = $this->read($path);
            if ($record !== null) {
                $records[] = $record;
            }
        }
        usort($records, static fn (array $a, array $b): int =>
            strcmp($a['started_at'], $b['started_at']) ?: strcmp($a['id'], $b['id']));
        return $records;
    }

    public function count(string $candidate): int
    {
        return count($this->files($candidate));
    }

    public function find(string $candidate, string $id): ?array
    {
        return $this->read($this->path($candidate, $id));
    }

    public function page(string $candidate, int $offset, int $limit): array
    {
        $files = $this->files($candidate);
        $times = [];
        foreach ($files as $path) {
            $times[$path] = filemtime($path) ?: 0;
        }
        usort($files, static fn (string $a, string $b): int =>
            ($times[$b] <=> $times[$a]) ?: strcmp($b, $a));
        $records = [];
        foreach (array_slice($files, max(0, $offset), max(1, min(20, $limit))) as $path) {
            $record = $this->read($path);
            if ($record !== null) {
                $records[] = array_intersect_key($record, array_flip([
                    'id', 'case_key', 'variant', 'status', 'checks', 'started_at',
                    'model', 'elapsed_ms', 'validation_error', 'error',
                ]));
            }
        }
        return $records;
    }

    private function files(string $candidate): array
    {
        $directory = dirname($this->path($candidate, str_repeat('0', 32)));
        return glob($directory . '/' . hash('sha256', $candidate) . '-*.json') ?: [];
    }

    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('검증 기록 읽기 실패');
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('검증 기록 읽기 잠금 실패');
            }
            $record = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
            return is_array($record) ? $record : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $candidate, string $id): string
    {
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $candidate) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
        ) {
            throw new DomainException('검증 기록 키가 올바르지 않습니다.');
        }
        $directory = rtrim($this->roots->root(), '/\\') . '/ai-feedback-validation';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('검증 저장소 생성 실패');
        }
        return $directory . '/' . hash('sha256', $candidate) . '-' . $id . '.json';
    }

    /** @param resource $handle */
    private function write(mixed $handle, array $record): void
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('검증 기록 쓰기 잠금 실패');
        }
        try {
            rewind($handle);
            if (! ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('검증 기록 저장 실패');
            }
        } finally {
            flock($handle, LOCK_UN);
        }
    }
}
