<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Domain;

use JsonException;

final class SubmissionFeedback
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $submissionId,
        private readonly array $content,
        private readonly string $inputHash,
        private readonly string $sourceHash,
        private readonly string $problemHash,
        private readonly int $resultCode,
        private readonly string $guideKey,
        private readonly int $guideRevision,
        private readonly string $analysisStatus,
        private readonly ?string $level,
        private readonly string $levelPolicyVersion,
        private readonly int $profileLastSubmissionId,
        private readonly int $assignmentRevision,
        private readonly string $profileKey,
        private readonly string $providerKey,
        private readonly string $profileFingerprint,
        private readonly string $modelKey,
        private readonly array $metadata,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
    }

    public function submissionId(): int { return $this->submissionId; }
    public function content(): array { return $this->content; }
    public function inputHash(): string { return $this->inputHash; }
    public function sourceHash(): string { return $this->sourceHash; }
    public function problemHash(): string { return $this->problemHash; }
    public function resultCode(): int { return $this->resultCode; }
    public function guideKey(): string { return $this->guideKey; }
    public function guideRevision(): int { return $this->guideRevision; }
    public function analysisStatus(): string { return $this->analysisStatus; }
    public function level(): ?string { return $this->level; }
    public function levelPolicyVersion(): string { return $this->levelPolicyVersion; }
    public function profileLastSubmissionId(): int { return $this->profileLastSubmissionId; }
    public function assignmentRevision(): int { return $this->assignmentRevision; }
    public function profileKey(): string { return $this->profileKey; }
    public function providerKey(): string { return $this->providerKey; }
    public function profileFingerprint(): string { return $this->profileFingerprint; }
    public function modelKey(): string { return $this->modelKey; }
    public function metadata(): array { return $this->metadata; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'content' => $this->json($this->content),
            'input_hash' => $this->inputHash,
            'source_hash' => $this->sourceHash,
            'problem_hash' => $this->problemHash,
            'result_code' => $this->resultCode,
            'guide_key' => $this->guideKey,
            'guide_revision' => $this->guideRevision,
            'analysis_status' => $this->analysisStatus,
            'level' => $this->level,
            'level_policy_version' => $this->levelPolicyVersion,
            'profile_last_submission_id' => $this->profileLastSubmissionId,
            'assignment_revision' => $this->assignmentRevision,
            'profile_key' => $this->profileKey,
            'provider_key' => $this->providerKey,
            'profile_fingerprint' => $this->profileFingerprint,
            'model_key' => $this->modelKey,
            'metadata' => $this->json($this->metadata),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['submission_id'] ?? 0),
            self::decode($row['content'] ?? ''),
            (string) ($row['input_hash'] ?? ''),
            (string) ($row['source_hash'] ?? ''),
            (string) ($row['problem_hash'] ?? ''),
            (int) ($row['result_code'] ?? 0),
            (string) ($row['guide_key'] ?? ''),
            (int) ($row['guide_revision'] ?? 0),
            (string) ($row['analysis_status'] ?? ''),
            ($row['level'] ?? null) === null ? null : (string) $row['level'],
            (string) ($row['level_policy_version'] ?? ''),
            (int) ($row['profile_last_submission_id'] ?? 0),
            (int) ($row['assignment_revision'] ?? 0),
            (string) ($row['profile_key'] ?? ''),
            (string) ($row['provider_key'] ?? ''),
            (string) ($row['profile_fingerprint'] ?? ''),
            (string) ($row['model_key'] ?? ''),
            self::decode($row['metadata'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
