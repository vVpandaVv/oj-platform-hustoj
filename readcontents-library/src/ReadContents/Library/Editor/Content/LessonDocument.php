<?php
/**
 * Editable lesson document model.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

final class LessonDocument
{
    /**
     * @param array<int, array<string, mixed>> $plateValue
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $visuals
     */
    public function __construct(
        public readonly string $lessonId,
        public readonly array $plateValue,
        public readonly array $assets = [],
        public readonly array $visuals = [],
        public readonly string $schemaVersion = '1.0.0',
        public readonly int $revision = 1
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lessonId' => $this->lessonId,
            'schemaVersion' => $this->schemaVersion,
            'revision' => $this->revision,
            'plateValue' => $this->plateValue,
            'assets' => $this->assets,
            'visuals' => $this->visuals,
        ];
    }
}
