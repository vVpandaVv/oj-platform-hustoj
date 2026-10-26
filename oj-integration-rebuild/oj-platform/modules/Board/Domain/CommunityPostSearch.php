<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityPostSearch
{
    /**
     * @param list<string> $statuses
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $keyword = '',
        private readonly ?string $contextType = null,
        private readonly ?int $contextId = null,
        private readonly string $answerState = 'all',
        private readonly array $statuses = ['publish'],
        private readonly bool $orphanedOnly = false
    ) {
        if (! in_array($this->kind, ['board', 'notice'], true)) {
            throw new InvalidArgumentException('검색할 게시글 종류가 올바르지 않습니다.');
        }
        if (! in_array($this->answerState, ['all', 'answered', 'unanswered'], true)) {
            throw new InvalidArgumentException('답변 상태 필터가 올바르지 않습니다.');
        }
    }

    public function kind(): string { return $this->kind; }
    public function keyword(): string { return trim($this->keyword); }
    public function contextType(): ?string { return $this->contextType; }
    public function contextId(): ?int { return $this->contextId; }
    public function answerState(): string { return $this->answerState; }
    /** @return list<string> */
    public function statuses(): array { return $this->statuses; }
    public function orphanedOnly(): bool { return $this->orphanedOnly; }
}
