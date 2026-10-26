<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonAiTaskDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $inputContractVersion,
        private readonly string $sourceKind,
        private readonly ?AddonAiTaskInstructionV1 $instruction = null
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::label($this->label);
        ValueValidator::safeToken($this->inputContractVersion, 'inputContractVersion', 64);
        ValueValidator::oneOf(
            $this->sourceKind,
            ['none', 'addon_payload', 'platform_submission'],
            'sourceKind'
        );
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function inputContractVersion(): string { return $this->inputContractVersion; }
    public function sourceKind(): string { return $this->sourceKind; }
    public function instruction(): ?AddonAiTaskInstructionV1 { return $this->instruction; }
}
