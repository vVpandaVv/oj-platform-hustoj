<?php
/**
 * Generic content hierarchy node.
 *
 * @package ContentsEditor\Content
 */

declare(strict_types=1);

namespace ContentsEditor\Content;

final class ContentNode
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ContentNodeType $type,
        public readonly ContentStatus $status = ContentStatus::DRAFT,
        public readonly ?string $parentId = null,
        public readonly int $order = 0,
        public readonly string $contentType = 'default',
        public readonly string $schemaVersion = '1.0.0',
        public readonly array $metadata = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'parentId' => $this->parentId,
            'order' => $this->order,
            'contentType' => $this->contentType,
            'schemaVersion' => $this->schemaVersion,
            'metadata' => $this->metadata,
        ];
    }
}
