<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class PublicProfileActivity
{
    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly string $occurredAt,
        private readonly string $url
    ) {
        if (trim($this->title) === '' || trim($this->url) === '') {
            throw new InvalidArgumentException('프로필 활동이 올바르지 않습니다.');
        }
    }

    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function url(): string { return $this->url; }
}
