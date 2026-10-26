<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemArchiveInspection
{
    /**
     * @param list<ProblemArchiveEntry> $entries
     */
    public function __construct(
        private readonly string $archiveName,
        private readonly string $archiveSha256,
        private readonly array $entries,
        private readonly int $totalUncompressedBytes
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/', $this->archiveSha256) !== 1
            || $this->entries === []
            || $this->totalUncompressedBytes < 0
        ) {
            throw new InvalidArgumentException('ZIP 검사 결과가 올바르지 않습니다.');
        }
    }

    public function archiveName(): string
    {
        return $this->archiveName;
    }

    public function archiveSha256(): string
    {
        return $this->archiveSha256;
    }

    /**
     * @return list<ProblemArchiveEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    public function totalUncompressedBytes(): int
    {
        return $this->totalUncompressedBytes;
    }

    public function collisionCount(): int
    {
        return count(array_filter(
            $this->entries,
            static fn (ProblemArchiveEntry $entry): bool => $entry->hasCollision()
        ));
    }

    /**
     * @return array<string, string>
     */
    public function collisionHashes(): array
    {
        $hashes = [];
        foreach ($this->entries as $entry) {
            if ($entry->collisionSha256() !== null) {
                $hashes[$entry->name()] = $entry->collisionSha256();
            }
        }

        return $hashes;
    }
}
