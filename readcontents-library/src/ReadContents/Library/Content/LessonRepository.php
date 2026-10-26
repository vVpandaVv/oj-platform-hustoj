<?php

declare(strict_types=1);

namespace ReadContents\Library\Content;

/**
 * Reads the migrated Contents Editor data (data/index.json, data/lessons/
 * *.json) and layers editorial overrides -- edited block content and the
 * draft/published status -- on top, stored in wp_options rather than back
 * on disk: edits must survive a plugin re-deploy, and the plugin directory
 * isn't guaranteed to be web-writable in the first place.
 *
 * All 152 lessons' overrides live in ONE option (a topicId-keyed map)
 * rather than one option per lesson, so listing/filtering by status costs
 * a single `get_option()` call per request instead of up to 152.
 */
final class LessonRepository
{
    private const OVERRIDES_OPTION = 'rcis_lesson_overrides';
    private const DEFAULT_STATUS = 'draft';

    /** @var array<string, mixed>|null */
    private ?array $indexCache = null;

    /** @var array<string, array<string, mixed>> */
    private array $lessonCache = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $overridesCache = null;

    /** @return array<string, mixed> */
    public function index(): array
    {
        if ($this->indexCache !== null) {
            return $this->indexCache;
        }

        $this->indexCache = $this->readJson($this->dataDir() . 'index.json');

        return $this->indexCache;
    }

    /**
     * All lessons across every chapter/section, flattened, in document
     * order -- for the console list view and bulk actions.
     *
     * @return list<array{topicId: string, lessonId: string, title: string, order: int}>
     */
    public function allLessons(): array
    {
        $lessons = [];
        foreach ((array) ($this->index()['chapters'] ?? []) as $chapter) {
            foreach ((array) ($chapter['sections'] ?? []) as $section) {
                foreach ((array) ($section['lessons'] ?? []) as $lesson) {
                    $lessons[] = $lesson;
                }
            }
        }

        return $lessons;
    }

    /** @return array<string, mixed> */
    public function lesson(string $topicId): array
    {
        $topicId = self::normalizeTopicId($topicId);
        if ($topicId === '') {
            return [];
        }
        if (isset($this->lessonCache[$topicId])) {
            return $this->lessonCache[$topicId];
        }

        $lesson = $this->readJson($this->dataDir() . 'lessons/' . $topicId . '.json');
        if ($lesson === []) {
            return [];
        }

        $override = $this->overrides()[$topicId] ?? [];
        if (isset($override['status']) && is_string($override['status'])) {
            $lesson['status'] = $override['status'];
        }
        if (isset($override['blocks']) && is_array($override['blocks'])) {
            $lesson['blocks'] = $override['blocks'];
        }
        $lesson['status'] ??= self::DEFAULT_STATUS;

        $this->lessonCache[$topicId] = $lesson;

        return $lesson;
    }

    /** Cheap status lookup for listing/filtering -- doesn't decode the lesson's block JSON. */
    public function status(string $topicId): string
    {
        $topicId = self::normalizeTopicId($topicId);

        return (string) ($this->overrides()[$topicId]['status'] ?? self::DEFAULT_STATUS);
    }

    /** @param list<array<string, mixed>> $blocks */
    public function saveBlocks(string $topicId, array $blocks): void
    {
        $topicId = self::normalizeTopicId($topicId);
        if ($topicId === '') {
            return;
        }

        $this->patchOverride($topicId, ['blocks' => $blocks]);
        unset($this->lessonCache[$topicId]);
    }

    public function setStatus(string $topicId, string $status): void
    {
        $topicId = self::normalizeTopicId($topicId);
        if ($topicId === '' || ($status !== 'draft' && $status !== 'published')) {
            return;
        }

        $this->patchOverride($topicId, ['status' => $status]);
        unset($this->lessonCache[$topicId]);
    }

    /** Publishes every lesson at once. Returns how many were changed. */
    public function publishAll(): int
    {
        $overrides = $this->overrides();
        $count = 0;
        foreach ($this->allLessons() as $lesson) {
            $topicId = (string) $lesson['topicId'];
            $overrides[$topicId] = array_merge($overrides[$topicId] ?? [], ['status' => 'published']);
            $count++;
        }

        $this->overridesCache = $overrides;
        $this->writeOverrides($overrides);
        $this->lessonCache = [];

        return $count;
    }

    public static function normalizeTopicId(string $topicId): string
    {
        $topicId = strtolower(trim($topicId));

        return preg_match('/^[0-9]+-[0-9]+-[0-9]+$/', $topicId) === 1 ? $topicId : '';
    }

    private function dataDir(): string
    {
        return READCONTENTS_LIBRARY_PATH . 'data/';
    }

    /** @param array<string, mixed> $patch */
    private function patchOverride(string $topicId, array $patch): void
    {
        $overrides = $this->overrides();
        $overrides[$topicId] = array_merge($overrides[$topicId] ?? [], $patch);
        $this->overridesCache = $overrides;
        $this->writeOverrides($overrides);
    }

    /** @return array<string, array<string, mixed>> */
    private function overrides(): array
    {
        if ($this->overridesCache !== null) {
            return $this->overridesCache;
        }

        if (! function_exists('get_option')) {
            $this->overridesCache = [];

            return [];
        }

        $value = get_option(self::OVERRIDES_OPTION, []);
        $this->overridesCache = is_array($value) ? $value : [];

        return $this->overridesCache;
    }

    /** @param array<string, array<string, mixed>> $overrides */
    private function writeOverrides(array $overrides): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(self::OVERRIDES_OPTION, $overrides, false);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        if (function_exists('wp_json_file_decode')) {
            $decoded = wp_json_file_decode($path, ['associative' => true]);

            return is_array($decoded) ? $decoded : [];
        }

        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
