<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
final class AddonNativeLearningPageV1
{
    public function __construct(private readonly array $items = [], private readonly ?int $nextBeforeId = null, private readonly array $actions = [], private readonly bool $available = true, private readonly string $message = '')
    {
        if (!array_is_list($items) || count($items) > 50 || !array_is_list($actions) || count($actions) > 4 || ($nextBeforeId !== null && $nextBeforeId < 1)) { throw new \InvalidArgumentException('Invalid native catalog page.'); }
        $ids = []; foreach ($items as $item) { if (!$item instanceof AddonNativeLearningItemV1 || isset($ids[$item->externalId()])) { throw new \InvalidArgumentException('Invalid native catalog item.'); } $ids[$item->externalId()] = true; }
        foreach ($actions as $link) { if (!$link instanceof AddonNativeLearningLinkV1) { throw new \InvalidArgumentException('Invalid native catalog action.'); } }
        ValueValidator::text($message, 'message', 240, true);
        if (!$available && ($items !== [] || $actions !== [] || $nextBeforeId !== null)) { throw new \InvalidArgumentException('Unavailable catalog cannot contain records.'); }
    }
    public function items(): array { return $this->items; }
    public function nextBeforeId(): ?int { return $this->nextBeforeId; }
    public function actions(): array { return $this->actions; }
    public function isAvailable(): bool { return $this->available; }
    public function message(): string { return $this->message; }
}
