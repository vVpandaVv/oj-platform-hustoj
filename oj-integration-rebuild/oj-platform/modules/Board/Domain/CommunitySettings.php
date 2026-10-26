<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunitySettings
{
    /**
     * @param list<string> $allowedExtensions
     */
    public function __construct(
        private readonly bool $globalPosting,
        private readonly bool $boardComments,
        private readonly bool $problemQuestions,
        private readonly bool $classQuestions,
        private readonly bool $contestQuestions,
        private readonly bool $groupQuestions,
        private readonly bool $noticeCommentsDefault,
        private readonly bool $attachments,
        private readonly array $allowedExtensions,
        private readonly bool $zipAllowed,
        private readonly int $maxFileBytes,
        private readonly int $maxPostFiles,
        private readonly int $maxCommentFiles,
        private readonly int $postRateLimit = 5,
        private readonly int $commentRateLimit = 20
    ) {
        if ($this->maxFileBytes < 1024 || $this->maxFileBytes > 10485760) {
            throw new InvalidArgumentException('첨부파일 제한은 1KB 이상 10MB 이하여야 합니다.');
        }
        if ($this->maxPostFiles < 0 || $this->maxPostFiles > 5) {
            throw new InvalidArgumentException('게시글 첨부 개수는 최대 5개입니다.');
        }
        if ($this->maxCommentFiles < 0 || $this->maxCommentFiles > 3) {
            throw new InvalidArgumentException('댓글 첨부 개수는 최대 3개입니다.');
        }
        if ($this->postRateLimit < 1 || $this->postRateLimit > 5) {
            throw new InvalidArgumentException('10분당 게시글 작성 제한은 1~5건이어야 합니다.');
        }
        if ($this->commentRateLimit < 1 || $this->commentRateLimit > 20) {
            throw new InvalidArgumentException('10분당 댓글 작성 제한은 1~20건이어야 합니다.');
        }
    }

    public function globalPosting(): bool { return $this->globalPosting; }
    public function boardComments(): bool { return $this->boardComments; }
    public function noticeCommentsDefault(): bool { return $this->noticeCommentsDefault; }
    public function attachments(): bool { return $this->attachments; }
    /** @return list<string> */
    public function allowedExtensions(): array { return $this->allowedExtensions; }
    public function zipAllowed(): bool { return $this->zipAllowed; }
    public function maxFileBytes(): int { return $this->maxFileBytes; }
    public function maxPostFiles(): int { return $this->maxPostFiles; }
    public function maxCommentFiles(): int { return $this->maxCommentFiles; }
    public function postRateLimit(): int { return $this->postRateLimit; }
    public function commentRateLimit(): int { return $this->commentRateLimit; }

    public function questionsEnabled(string $contextType): bool
    {
        return match ($contextType) {
            'global' => $this->globalPosting,
            'problem' => $this->problemQuestions,
            'class' => $this->classQuestions,
            'contest' => $this->contestQuestions,
            'group' => $this->groupQuestions,
            default => false,
        };
    }
}
