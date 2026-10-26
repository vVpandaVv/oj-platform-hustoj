<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonContextSnapshotV1
{
    /** @param array<string, bool|int|float|string|null> $attributes */
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        private readonly string $label,
        private readonly string $lifecycleState,
        private readonly string $revision,
        private readonly string $actorRelation,
        private readonly array $attributes = []
    ) {
        ValueValidator::oneOf($this->type, ['site', 'class', 'group', 'user'], 'type');
        ValueValidator::safeToken($this->id, 'id', 160);
        ValueValidator::label($this->label);
        ValueValidator::safeToken($this->lifecycleState, 'lifecycleState', 64);
        ValueValidator::safeToken($this->revision, 'revision', 160);
        ValueValidator::safeToken($this->actorRelation, 'actorRelation', 80);
        ValueValidator::scalarMap($this->attributes, 'attributes');
    }

    public function type(): string { return $this->type; }
    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function lifecycleState(): string { return $this->lifecycleState; }
    public function revision(): string { return $this->revision; }
    public function actorRelation(): string { return $this->actorRelation; }
    /** @return array<string, bool|int|float|string|null> */
    public function attributes(): array { return $this->attributes; }
}
