<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeCompletionAnswerV1
{
    /** @param array<string, string> $answers */
    public function __construct(
        private readonly int $actorUserId,
        private readonly int $problemId,
        private readonly string $templateHash,
        private readonly array $answers,
        private readonly string $contextType = 'general',
        private readonly ?int $contextId = null
    ) {
        new AddonCodeCompletionQueryV1($actorUserId, $problemId, $contextType, $contextId);
        if (preg_match('/^[a-f0-9]{64}$/D', $templateHash) !== 1 || count($answers) > 30) {
            throw new InvalidArgumentException('Invalid code completion answer.');
        }
        foreach ($answers as $id => $answer) {
            // CRLF normalization in the existing source mode can halve the byte size.
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', (string) $id) !== 1
                || ! is_string($answer) || strlen($answer) > 131072 || preg_match('//u', $answer) !== 1) {
                throw new InvalidArgumentException('Invalid code completion blank answer.');
            }
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function problemId(): int { return $this->problemId; }
    public function templateHash(): string { return $this->templateHash; }
    /** @return array<string, string> */
    public function answers(): array { return $this->answers; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): ?int { return $this->contextId; }
}
