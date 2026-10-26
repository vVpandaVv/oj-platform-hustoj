<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class PublicProfileSection
{
    /**
     * @param list<PublicProfileMetric> $metrics
     * @param list<PublicProfileActivity> $activities
     * @param list<string> $highlights
     */
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $summary,
        private readonly array $metrics,
        private readonly array $activities,
        private readonly array $highlights = []
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_.]*$/', $this->key) !== 1
            || trim($this->title) === ''
        ) {
            throw new InvalidArgumentException('프로필 섹션이 올바르지 않습니다.');
        }
        foreach ($this->metrics as $metric) {
            if (! $metric instanceof PublicProfileMetric) {
                throw new InvalidArgumentException('프로필 지표 형식이 올바르지 않습니다.');
            }
        }
        foreach ($this->activities as $activity) {
            if (! $activity instanceof PublicProfileActivity) {
                throw new InvalidArgumentException('프로필 활동 형식이 올바르지 않습니다.');
            }
        }
        foreach ($this->highlights as $highlight) {
            if (! is_string($highlight) || trim($highlight) === '') {
                throw new InvalidArgumentException('프로필 주요 항목 형식이 올바르지 않습니다.');
            }
        }
    }

    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    public function summary(): string { return $this->summary; }

    /** @return list<PublicProfileMetric> */
    public function metrics(): array { return $this->metrics; }

    /** @return list<PublicProfileActivity> */
    public function activities(): array { return $this->activities; }

    /** @return list<string> */
    public function highlights(): array { return $this->highlights; }
}
