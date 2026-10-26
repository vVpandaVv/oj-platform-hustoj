<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonPrivacyDataDefinitionV1
{
    /** @param list<string> $dataCategories */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $dataCategories,
        private readonly string $retention,
        private readonly string $administrationNote
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::label($this->label);
        ValueValidator::stringList($this->dataCategories, 'dataCategories', 24);
        foreach ($this->dataCategories as $category) {
            ValueValidator::text($category, 'dataCategories', 80);
        }
        ValueValidator::text($this->retention, 'retention', 160);
        ValueValidator::text($this->administrationNote, 'administrationNote', 240, true);
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    /** @return list<string> */
    public function dataCategories(): array { return $this->dataCategories; }
    public function retention(): string { return $this->retention; }
    public function administrationNote(): string { return $this->administrationNote; }
}
