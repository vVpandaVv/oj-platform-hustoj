<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;

final class AgreementDocument
{
    public function __construct(
        private readonly int $documentId,
        private readonly string $typeSlug,
        private readonly string $version,
        private readonly string $title,
        private readonly string $content,
        private readonly bool $required
    ) {
        if ($this->documentId < 1 || trim($this->typeSlug) === '' || trim($this->version) === '') {
            throw new InvalidArgumentException('동의 문서 식별 정보가 올바르지 않습니다.');
        }
    }

    public function documentId(): int
    {
        return $this->documentId;
    }

    public function typeSlug(): string
    {
        return $this->typeSlug;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isForUserConsent(): bool
    {
        return ! (AgreementDocumentType::tryFrom($this->typeSlug)?->isGuardianOnly() ?? false);
    }
}
