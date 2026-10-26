<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmitSubmissionCommand
{
    /**
     * @param array<string, string> $completionAnswers
     * @param list<string> $orderingBlockIds
     */
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $userLogin,
        private readonly string $displayName,
        private readonly int $problemId,
        private readonly SubmissionMode $mode,
        private readonly int $languageId,
        private readonly string $source,
        private readonly string $templateHash,
        private readonly array $completionAnswers,
        private readonly string $blocklyWorkspace,
        private readonly string $blocklyProfile,
        private readonly string $requestKey,
        private readonly string $ipAddress,
        private readonly int $contestId = 0,
        private readonly int $contestProblemNumber = -1,
        private readonly ?int $classId = null,
        private readonly ?int $groupId = null,
        private readonly array $orderingBlockIds = []
    ) {
        if (
            $this->actorUserId < 1
            || trim($this->userLogin) === ''
            || $this->problemId < 1
            || $this->languageId < 0
            || preg_match('/^[a-f0-9]{64}$/', $this->requestKey) !== 1
        ) {
            throw new InvalidArgumentException('제출 요청 정보가 올바르지 않습니다.');
        }
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function mode(): SubmissionMode
    {
        return $this->mode;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function templateHash(): string
    {
        return $this->templateHash;
    }

    /**
     * @return array<string, string>
     */
    public function completionAnswers(): array
    {
        return $this->completionAnswers;
    }

    /** @return list<string> */
    public function orderingBlockIds(): array
    {
        return $this->orderingBlockIds;
    }

    public function blocklyWorkspace(): string
    {
        return $this->blocklyWorkspace;
    }

    public function blocklyProfile(): string
    {
        return $this->blocklyProfile;
    }

    public function requestKey(): string
    {
        return $this->requestKey;
    }

    public function ipAddress(): string
    {
        return $this->ipAddress;
    }

    public function contestId(): int
    {
        return $this->contestId;
    }

    public function contestProblemNumber(): int
    {
        return $this->contestProblemNumber;
    }

    public function classId(): ?int
    {
        return $this->classId;
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }
}
