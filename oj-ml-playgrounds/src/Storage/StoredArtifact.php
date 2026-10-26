<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Storage;

final class StoredArtifact
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $storageKey,
        public readonly string $sha256,
        public readonly int $bytes,
        public readonly string $mimeType
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(string $originalName): array
    {
        return [
            'uuid' => $this->uuid,
            'storage_key' => $this->storageKey,
            'original_name' => $originalName,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'mime_type' => $this->mimeType,
        ];
    }
}
