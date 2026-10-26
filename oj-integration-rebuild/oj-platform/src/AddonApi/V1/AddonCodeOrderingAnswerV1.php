<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeOrderingAnswerV1
{
    /** @param list<string> $blockIds */
    public function __construct(
        private readonly int $actorUserId,
        private readonly int $problemId,
        private readonly string $templateHash,
        private readonly array $blockIds,
        private readonly string $contextType = 'general',
        private readonly ?int $contextId = null
    ) {
        new AddonCodeOrderingQueryV1($actorUserId, $problemId, $contextType, $contextId);
        if (preg_match('/^[a-f0-9]{64}$/D', $templateHash) !== 1
            || ! array_is_list($blockIds) || count($blockIds) > 200) {
            throw new InvalidArgumentException('Invalid code ordering answer.');
        }
        foreach ($blockIds as $id) {
            if (! is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid code ordering block ID.');
            }
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function problemId(): int { return $this->problemId; }
    public function templateHash(): string { return $this->templateHash; }
    /** @return list<string> */
    public function blockIds(): array { return $this->blockIds; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): ?int { return $this->contextId; }
}
