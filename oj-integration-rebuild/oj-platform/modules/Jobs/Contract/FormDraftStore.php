<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;

interface FormDraftStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference;

    public function find(
        FormDraftReference $reference,
        int $ownerUserId
    ): ?FormDraft;

    public function discard(
        FormDraftReference $reference,
        int $ownerUserId
    ): void;

    public function purgeExpired(int $limit = 100): int;
}
