<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

final class Material {
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $description,
        public readonly string $storage_filename,
        public readonly string $original_filename,
        public readonly string $extension,
        public readonly string $mime_type,
        public readonly int $file_size,
        public readonly int $uploaded_by_user_id,
        public readonly string $created_at,
        public readonly string $updated_at,
        public readonly string $source_url = '',
        public readonly string $category = '',
        public readonly array $tags = array(),
        public readonly bool $is_pinned = false,
        public readonly string $publication_status = 'published',
        public readonly ?string $publish_from = null,
        public readonly ?string $publish_until = null
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function from_row(array $row): self {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['title'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) ($row['storage_filename'] ?? ''),
            (string) ($row['original_filename'] ?? ''),
            (string) ($row['extension'] ?? ''),
            (string) ($row['mime_type'] ?? ''),
            (int) ($row['file_size'] ?? 0),
            (int) ($row['uploaded_by_user_id'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (string) ($row['source_url'] ?? ''),
            (string) ($row['category'] ?? ''),
            self::decode_tags($row['tags_json'] ?? ''),
            ! empty($row['is_pinned']),
            (string) ($row['publication_status'] ?? 'published'),
            ! empty($row['publish_from']) ? (string) $row['publish_from'] : null,
            ! empty($row['publish_until']) ? (string) $row['publish_until'] : null
        );
    }

    private static function decode_tags(mixed $value): array {
        $tags = is_string($value) ? json_decode($value, true) : null;
        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : array();
    }

    public function publication_state(?int $now = null): string {
        $now ??= time();
        if ($this->publication_status !== 'published') return $this->publication_status;
        if ($this->publish_until !== null && strtotime($this->publish_until . ' UTC') <= $now) return 'expired';
        if ($this->publish_from !== null && strtotime($this->publish_from . ' UTC') > $now) return 'scheduled';
        return 'published';
    }

    public function is_available(?int $now = null): bool {
        return $this->publication_state($now) === 'published';
    }

    public function type(): string {
        if ($this->is_google_link()) return 'google';
        if ($this->is_image()) return 'image';
        return array('ppt' => 'presentation', 'pptx' => 'presentation', 'doc' => 'document', 'docx' => 'document',
            'xls' => 'spreadsheet', 'xlsx' => 'spreadsheet', 'txt' => 'text', 'csv' => 'text')[$this->extension] ?? $this->extension;
    }

    public function is_viewable_inline(): bool {
        return ! $this->is_google_link() && in_array($this->extension, array('pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp'), true);
    }

    public function is_google_link(): bool {
        return $this->source_url !== '';
    }

    public function is_image(): bool {
        return ! $this->is_google_link() && in_array($this->extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true);
    }

    public function uses_google_viewer(): bool {
        return $this->is_google_link() || in_array($this->extension, array('ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'), true);
    }

    public function content_type(): string {
        return array(
            'pdf' => 'application/pdf', 'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain; charset=UTF-8', 'csv' => 'text/plain; charset=UTF-8',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        )[$this->extension] ?? 'application/octet-stream';
    }

    public function formatted_size(): string {
        if ($this->is_google_link()) {
            return __('Google 공유 링크', 'learning-materials');
        }
        if ($this->file_size >= 1024 * 1024) {
            return number_format($this->file_size / (1024 * 1024), 1) . ' MB';
        }
        if ($this->file_size >= 1024) {
            return number_format($this->file_size / 1024, 1) . ' KB';
        }

        return $this->file_size . ' B';
    }
}
