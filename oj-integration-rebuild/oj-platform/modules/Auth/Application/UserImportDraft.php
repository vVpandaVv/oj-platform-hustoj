<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;

final class UserImportDraft
{
    public function __construct(
        private readonly UserImportPreview $preview,
        private readonly ?FormDraftReference $reference
    ) {
    }

    public function preview(): UserImportPreview
    {
        return $this->preview;
    }

    public function reference(): ?FormDraftReference
    {
        return $this->reference;
    }
}
