<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class Material_Settings {
    public function __construct(
        public readonly string $category = '',
        public readonly array $tags = array(),
        public readonly bool $pinned = false,
        public readonly string $status = 'published',
        public readonly ?string $from = null,
        public readonly ?string $until = null
    ) {}

    public static function from_input(array $input, DateTimeZone $timezone, ?Material $existing = null): self {
        $text = static function (string $key, string $fallback = '') use ($input): string {
            if (! array_key_exists($key, $input)) return $fallback;
            if (! is_string($input[$key])) throw new DomainException(__('자료 설정 값이 올바르지 않습니다.', 'learning-materials'));
            if (preg_match('//u', $input[$key]) !== 1) throw new DomainException(__('자료 설정의 문자 인코딩을 확인해 주세요.', 'learning-materials'));
            return trim(strip_tags($input[$key]));
        };
        $category = $text('category', $existing?->category ?? '');
        if (preg_match_all('/./us', $category) > 120) throw new DomainException(__('분류는 120자 이내로 입력해 주세요.', 'learning-materials'));
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $text('tags', implode(', ', $existing?->tags ?? array())))), static fn ($tag) => $tag !== '')));
        if (count($tags) > 10) throw new DomainException(__('태그는 최대 10개까지 입력할 수 있습니다.', 'learning-materials'));
        foreach ($tags as $tag) {
            if (preg_match_all('/./us', $tag) > 40) throw new DomainException(__('태그는 각각 40자 이내로 입력해 주세요.', 'learning-materials'));
        }
        $status = $text('publication_status', $existing?->publication_status ?? 'draft');
        if (! in_array($status, array('draft', 'published', 'archived'), true)) throw new DomainException(__('공개 상태가 올바르지 않습니다.', 'learning-materials'));
        $date = static function (string $key, ?string $fallback) use ($input, $text, $timezone): ?string {
            if (! array_key_exists($key, $input)) return $fallback;
            $value = $text($key);
            if ($value === '') return null;
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if (! $parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d\TH:i') !== $value) {
                throw new DomainException(__('공개 기간의 날짜와 시간을 확인해 주세요.', 'learning-materials'));
            }
            return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $from = $date('publish_from', $existing?->publish_from);
        $until = $date('publish_until', $existing?->publish_until);
        if ($from !== null && $until !== null && $from >= $until) throw new DomainException(__('공개 종료는 시작보다 늦어야 합니다.', 'learning-materials'));
        $pinned = array_key_exists('publication_status', $input) ? ($input['is_pinned'] ?? '') === '1' : ($existing?->is_pinned ?? false);
        return new self($category, $tags, $pinned, $status, $from, $until);
    }

    public function row(): array {
        return array('category' => $this->category, 'tags_json' => json_encode($this->tags, JSON_UNESCAPED_UNICODE),
            'is_pinned' => (int) $this->pinned, 'publication_status' => $this->status, 'publish_from' => $this->from, 'publish_until' => $this->until);
    }

    public static function local_date(?string $utc, DateTimeZone $timezone): string {
        return $utc === null ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d\TH:i');
    }
}
