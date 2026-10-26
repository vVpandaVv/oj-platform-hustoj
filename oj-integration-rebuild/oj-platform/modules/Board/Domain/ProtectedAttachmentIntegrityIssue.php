<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class ProtectedAttachmentIntegrityIssue
{
    private const KINDS = [
        'record_invalid',
        'duplicate_reference',
        'file_missing',
        'parent_missing',
        'comment_missing',
        'comment_parent_mismatch',
        'file_orphaned',
    ];

    public function __construct(
        private readonly string $kind,
        private readonly ?int $attachmentId,
        private readonly string $storageName,
        private readonly string $detail
    ) {
        if (
            ! in_array($this->kind, self::KINDS, true)
            || ($this->attachmentId !== null && $this->attachmentId < 1)
            || trim($this->storageName) === ''
            || trim($this->detail) === ''
        ) {
            throw new InvalidArgumentException('보호 첨부 무결성 문제 정보가 올바르지 않습니다.');
        }
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function attachmentId(): ?int
    {
        return $this->attachmentId;
    }

    public function storageName(): string
    {
        return $this->storageName;
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
