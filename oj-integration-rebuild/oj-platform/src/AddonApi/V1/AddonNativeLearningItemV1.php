<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
final class AddonNativeLearningItemV1
{
    public function __construct(private readonly string $externalId, private readonly string $title, private readonly string $typeLabel, private readonly AddonNativeLearningLinkV1 $primaryLink, private readonly array $secondaryLinks = [], private readonly string $statusLabel = '')
    {
        ValueValidator::safeToken($externalId, 'externalId', 128); if (!str_contains($externalId, ':')) { throw new \InvalidArgumentException('Native catalog IDs must be namespaced.'); }
        ValueValidator::text($title, 'title', 240); ValueValidator::label($typeLabel, 'typeLabel'); ValueValidator::text($statusLabel, 'statusLabel', 160, true);
        if (!array_is_list($secondaryLinks) || count($secondaryLinks) > 3) { throw new \InvalidArgumentException('Invalid native catalog links.'); }
        foreach ($secondaryLinks as $link) { if (!$link instanceof AddonNativeLearningLinkV1) { throw new \InvalidArgumentException('Invalid native catalog link.'); } }
    }
    public function externalId(): string { return $this->externalId; }
    public function title(): string { return $this->title; }
    public function typeLabel(): string { return $this->typeLabel; }
    public function primaryLink(): AddonNativeLearningLinkV1 { return $this->primaryLink; }
    public function secondaryLinks(): array { return $this->secondaryLinks; }
    public function statusLabel(): string { return $this->statusLabel; }
}
