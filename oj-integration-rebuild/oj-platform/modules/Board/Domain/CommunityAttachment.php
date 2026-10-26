<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityAttachment
{
    public function __construct(
        private readonly int $id,
        private readonly int $postId,
        private readonly ?int $commentId,
        private readonly string $displayName,
        private readonly string $mimeType,
        private readonly int $size,
        private readonly string $storagePath
    ) {
        if (
            $this->id < 1
            || $this->postId < 1
            || ($this->commentId !== null && $this->commentId < 1)
            || $this->size < 1
            || trim($this->displayName) === ''
            || strlen($this->displayName) > 255
            || preg_match('//u', $this->displayName) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $this->displayName) === 1
            || basename(str_replace('\\', '/', $this->displayName)) !== $this->displayName
            || preg_match(
                '/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/',
                $this->mimeType
            ) !== 1
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/',
                $this->storagePath
            ) !== 1
        ) {
            throw new InvalidArgumentException('첨부파일 정보가 올바르지 않습니다.');
        }
    }

    public function id(): int { return $this->id; }
    public function postId(): int { return $this->postId; }
    public function commentId(): ?int { return $this->commentId; }
    public function displayName(): string { return $this->displayName; }
    public function mimeType(): string { return $this->mimeType; }
    public function size(): int { return $this->size; }
    public function storagePath(): string { return $this->storagePath; }
}
