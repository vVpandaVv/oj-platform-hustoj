<?php
/**
 * Content package import/export service.
 *
 * @package ContentsEditor\ImportExport
 */

declare(strict_types=1);

namespace ContentsEditor\ImportExport;

use ContentsEditor\Content\ContentNode;
use ContentsEditor\Content\ContentNodeType;
use ContentsEditor\Content\ContentStatus;
use ContentsEditor\Storage\ContentRepository;
use InvalidArgumentException;
use ZipArchive;

final class ContentPackageService
{
    private const LEGACY_TOPIC_PACKAGE_KIND = 'contents-editor-topic';
    private const PACKAGE_KIND = 'contents-editor-package';
    private const PACKAGE_VERSION = '1.0.0';
    private const ZIP_MANIFEST_FILES = [
        'manifest.json',
        'package.json',
        'contents-editor-package.json',
        'contents-editor.json',
        '.contents-editor.json',
    ];
    private const URL_FIELD_NAMES = [
        'href',
        'poster',
        'src',
        'thumbnail',
        'url',
    ];
    private const LESSON_CONFLICT_CREATE_NEW = 'create_new';
    private const LESSON_CONFLICT_REPLACE = 'replace';
    private const LESSON_CONFLICT_SKIP = 'skip';

    public function __construct(private readonly ContentRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function exportTopic(string $topic_id): array
    {
        return $this->exportNode($topic_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportNode(string $node_id): array
    {
        $node = $this->repository->getNode($node_id);

        if (! $node) {
            throw new InvalidArgumentException(__('내보낼 목차 항목을 찾을 수 없습니다.', 'contents-editor'));
        }

        $tree = $this->buildScopedTree($node);
        $documents = [];

        foreach ($this->lessonNodesFromTree($tree) as $lesson) {
            $lesson_id = (string) ($lesson['id'] ?? '');

            if ('' !== $lesson_id) {
                $documents[$lesson_id] = $this->repository->getLessonDocument($lesson_id)->toArray();
            }
        }

        return [
            'kind' => self::PACKAGE_KIND,
            'packageVersion' => self::PACKAGE_VERSION,
            'exportedAt' => gmdate('c'),
            'scope' => $node->type->value,
            'rootNode' => $node->toArray(),
            'tree' => $tree,
            'lessonDocuments' => $documents,
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public function previewTopicPackage(array $package): array
    {
        $this->assertSupportedPackage($package);

        return $this->previewImportPlan($package);
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function importTopicPackage(array $package, array $options = []): array
    {
        $this->assertSupportedPackage($package);

        $tree = $package['tree'];
        $documents = isset($package['lessonDocuments']) && is_array($package['lessonDocuments'])
            ? $package['lessonDocuments']
            : [];
        $lesson_conflict_policy = $this->readLessonConflictPolicy($options['lessonConflictPolicy'] ?? null);
        $lesson_conflict_policy_by_source_id = $this->readLessonConflictPolicyMap($options['lessonConflictPolicyBySourceId'] ?? []);
        $preview = $this->previewImportPlan($package);
        $id_map = [];
        $created = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $reused = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $replaced = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $skipped = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];

        $source_topic = $this->readNode($tree['topic'] ?? null, ContentNodeType::TOPIC);
        $topic = $this->importContainerNode($source_topic, ContentNodeType::TOPIC, null, $created, $reused);
        $id_map[(string) $source_topic['id']] = $topic->id;

        $chapters = $tree['chapters'] ?? [];

        if (! is_array($chapters)) {
            $chapters = [];
        }

        $chapter_order = $this->nextOrderForParent(ContentNodeType::CHAPTER, $topic->id);

        foreach ($chapters as $chapter_group) {
            if (! is_array($chapter_group)) {
                continue;
            }

            $source_chapter = $this->readNode($chapter_group['chapter'] ?? null, ContentNodeType::CHAPTER);
            $chapter = $this->importContainerNode($source_chapter, ContentNodeType::CHAPTER, $topic->id, $created, $reused, $chapter_order);
            $id_map[(string) $source_chapter['id']] = $chapter->id;

            $sections = $chapter_group['sections'] ?? [];

            if (! is_array($sections)) {
                $sections = [];
            }

            $section_order = $this->nextOrderForParent(ContentNodeType::SECTION, $chapter->id);

            foreach ($sections as $section_group) {
                if (! is_array($section_group)) {
                    continue;
                }

                $source_section = $this->readNode($section_group['section'] ?? null, ContentNodeType::SECTION);
                $section = $this->importContainerNode($source_section, ContentNodeType::SECTION, $chapter->id, $created, $reused, $section_order);
                $id_map[(string) $source_section['id']] = $section->id;

                $lessons = $section_group['lessons'] ?? [];

                if (! is_array($lessons)) {
                    $lessons = [];
                }

                $lesson_order = $this->nextOrderForParent(ContentNodeType::LESSON, $section->id);

                foreach ($lessons as $source_lesson) {
                    $source_lesson = $this->readNode($source_lesson, ContentNodeType::LESSON);
                    $source_lesson_id = (string) $source_lesson['id'];
                    $document = $this->documentForLesson($documents, $source_lesson_id);
                    $lesson_match = $this->findMatchingNodeMatch($source_lesson, ContentNodeType::LESSON, $section->id);
                    $lesson_conflict_policy_for_lesson = $lesson_conflict_policy_by_source_id[$source_lesson_id] ?? $lesson_conflict_policy;

                    if ($lesson_match && self::LESSON_CONFLICT_CREATE_NEW !== $lesson_conflict_policy_for_lesson) {
                        /** @var ContentNode $existing_lesson */
                        $existing_lesson = $lesson_match['node'];
                        $id_map[$source_lesson_id] = $existing_lesson->id;

                        if (self::LESSON_CONFLICT_SKIP === $lesson_conflict_policy_for_lesson) {
                            ++$skipped['lessons'];
                            continue;
                        }

                        $lesson = $this->updateImportedNode($existing_lesson, $source_lesson, $section->id);
                        ++$replaced['lessons'];

                        if ($document) {
                            $this->saveImportedLessonDocument($lesson->id, $document);
                        }

                        continue;
                    }

                    $lesson = $this->createImportedNode($source_lesson, ContentNodeType::LESSON, $section->id, $lesson_order++);
                    $id_map[$source_lesson_id] = $lesson->id;
                    ++$created['lessons'];

                    if ($document) {
                        $this->saveImportedLessonDocument($lesson->id, $document);
                    }
                }
            }
        }

        return [
            'topic' => $topic->toArray(),
            'idMap' => $id_map,
            'created' => $created,
            'reused' => $reused,
            'replaced' => $replaced,
            'skipped' => $skipped,
            'conflicts' => $preview['conflicts'],
        ];
    }

    /**
     * Imports a ZIP package whose manifest contains the normal JSON package and whose
     * media files are referenced by relative paths such as assets/images/diagram.png.
     *
     * @return array<string, mixed>
     */
    public function previewZipPackage(string $zip_path): array
    {
        $zip = $this->openZipReader($zip_path);

        try {
            $zip_package = $this->readZipPackage($zip);
            $package = $zip_package['package'];
            $preview = $this->previewTopicPackage($package);
            $preview['assetsDetected'] = count($this->collectRelativeAssetPaths($package));

            return $preview;
        } finally {
            $this->closeZipReader($zip);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function importZipPackage(string $zip_path, array $options = []): array
    {
        $zip = $this->openZipReader($zip_path);

        try {
            $zip_package = $this->readZipPackage($zip);
            $package = $zip_package['package'];
            $base_dir = $zip_package['baseDir'];
            $asset_paths = $this->collectRelativeAssetPaths($package);
            $asset_map = [];

            foreach ($asset_paths as $asset_path) {
                $asset_map[$asset_path] = $this->importZipAsset(
                    $zip,
                    $base_dir,
                    $asset_path,
                    $this->assetMetadataForPath($package, $asset_path)
                );
            }

            if ($asset_map) {
                $package = $this->replaceAssetReferences($package, $asset_map);
            }

            $result = $this->importTopicPackage($package, $options);
            $result['assetsImported'] = count($asset_map);
            $result['assetMap'] = $asset_map;

            return $result;
        } finally {
            $this->closeZipReader($zip);
        }
    }

    private function openZipReader(string $zip_path): object
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $opened = $zip->open($zip_path);

            if (true !== $opened) {
                throw new InvalidArgumentException(__('The uploaded file is not a readable ZIP package.', 'contents-editor'));
            }

            return $zip;
        }

        $pclzip_path = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/class-pclzip.php' : '';

        if (is_string($pclzip_path) && '' !== $pclzip_path && is_readable($pclzip_path)) {
            require_once $pclzip_path;
        }

        if (! class_exists('PclZip')) {
            throw new InvalidArgumentException(__('ZIP package import requires PHP ZipArchive or WordPress PclZip.', 'contents-editor'));
        }

        $zip = new \PclZip($zip_path);
        $list = $zip->listContent();

        if (! is_array($list)) {
            $message = method_exists($zip, 'errorInfo') ? (string) $zip->errorInfo(true) : '';

            throw new InvalidArgumentException($message ?: __('The uploaded file is not a readable ZIP package.', 'contents-editor'));
        }

        return $zip;
    }

    private function closeZipReader(object $zip): void
    {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
    }

    /**
     * @return array{package: array<string, mixed>, baseDir: string}
     */
    private function readZipPackage(object $zip): array
    {
        $manifest_entry = $this->findZipManifestEntry($zip);

        if (null === $manifest_entry) {
            throw new InvalidArgumentException(__('ZIP package must contain manifest.json, package.json, contents-editor-package.json, contents-editor.json, .contents-editor.json, or a supported package JSON.', 'contents-editor'));
        }

        $manifest = $this->zipEntryContent($zip, $manifest_entry);

        if (! is_string($manifest)) {
            throw new InvalidArgumentException(__('ZIP package manifest could not be read.', 'contents-editor'));
        }

        $package = json_decode($manifest, true);

        if (! is_array($package) || JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException(__('ZIP package manifest is not valid JSON.', 'contents-editor'));
        }

        $base_dir = str_replace('\\', '/', dirname($manifest_entry));
        $base_dir = '.' === $base_dir ? '' : trim($base_dir, '/') . '/';

        return [
            'package' => $package,
            'baseDir' => $base_dir,
        ];
    }

    private function findZipManifestEntry(object $zip): ?string
    {
        $root_matches = [];
        $nested_matches = [];
        $json_candidates = [];

        foreach ($this->zipEntryNames($zip) as $entry) {
            if (! $this->isSafeZipEntryName($entry)) {
                continue;
            }

            $normalized = trim(str_replace('\\', '/', $entry), '/');

            if (str_ends_with($normalized, '/')) {
                continue;
            }

            $basename = basename($normalized);

            if (! in_array($basename, self::ZIP_MANIFEST_FILES, true)) {
                if (str_ends_with($normalized, '.json')) {
                    $json_candidates[] = $normalized;
                }

                continue;
            }

            if ($basename === $normalized) {
                $root_matches[] = $normalized;
            } else {
                $nested_matches[] = $normalized;
            }
        }

        foreach ($root_matches as $candidate) {
            $candidate_package = $this->readZipEntryAsPackage($zip, $candidate);
            if (null === $candidate_package) {
                continue;
            }

            return $candidate;
        }

        foreach ($nested_matches as $candidate) {
            $candidate_package = $this->readZipEntryAsPackage($zip, $candidate);
            if (null === $candidate_package) {
                continue;
            }

            return $candidate;
        }

        foreach ($json_candidates as $candidate) {
            $candidate_package = $this->readZipEntryAsPackage($zip, $candidate);
            if (null === $candidate_package) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readZipEntryAsPackage(object $zip, string $entry): ?array
    {
        $entry_content = $this->zipEntryContent($zip, $entry);

        if (! is_string($entry_content)) {
            return null;
        }

        $decoded = json_decode($entry_content, true);

        if (! is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            return null;
        }

        $kind = $decoded['kind'] ?? '';
        if (! in_array($kind, [self::PACKAGE_KIND, self::LEGACY_TOPIC_PACKAGE_KIND], true)) {
            return null;
        }

        if (! isset($decoded['tree']) || ! is_array($decoded['tree'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function zipEntryNames(object $zip): array
    {
        if ($zip instanceof ZipArchive) {
            $names = [];

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->getNameIndex($index);

                if (is_string($entry)) {
                    $names[] = $entry;
                }
            }

            return $names;
        }

        if ($zip instanceof \PclZip) {
            $list = $zip->listContent();

            if (! is_array($list)) {
                return [];
            }

            return array_values(
                array_filter(
                    array_map(
                        static fn (array $entry): string => isset($entry['filename']) && is_string($entry['filename'])
                            ? $entry['filename']
                            : '',
                        $list
                    )
                )
            );
        }

        return [];
    }

    private function zipEntryContent(object $zip, string $entry): string|false
    {
        if ($zip instanceof ZipArchive) {
            return $zip->getFromName($entry);
        }

        if ($zip instanceof \PclZip) {
            $extracted = $zip->extract(
                PCLZIP_OPT_BY_NAME,
                $entry,
                PCLZIP_OPT_EXTRACT_AS_STRING
            );

            if (! is_array($extracted) || ! isset($extracted[0]['content']) || ! is_string($extracted[0]['content'])) {
                return false;
            }

            return $extracted[0]['content'];
        }

        return false;
    }

    private function zipEntryExists(object $zip, string $entry): bool
    {
        if ($zip instanceof ZipArchive) {
            return false !== $zip->locateName($entry);
        }

        return in_array($entry, $this->zipEntryNames($zip), true);
    }

    private function isSafeZipEntryName(string $entry): bool
    {
        $entry = str_replace('\\', '/', $entry);

        return '' !== trim($entry)
            && ! str_starts_with($entry, '/')
            && ! str_starts_with($entry, '__MACOSX/')
            && ! str_contains($entry, "\0")
            && ! str_contains($entry, ':')
            && ! preg_match('#(^|/)\.\.(/|$)#', $entry);
    }

    /**
     * @param array<string, mixed> $package
     * @return array<int, string>
     */
    private function collectRelativeAssetPaths(array $package): array
    {
        $paths = [];
        $documents = isset($package['lessonDocuments']) && is_array($package['lessonDocuments'])
            ? $package['lessonDocuments']
            : [];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            $assets = isset($document['assets']) && is_array($document['assets'])
                ? $document['assets']
                : [];

            foreach ($assets as $asset) {
                if (is_array($asset) && isset($asset['url']) && is_string($asset['url'])) {
                    $this->addRelativeAssetPath($asset['url'], $paths);
                }
            }

            $this->collectRelativeAssetPathsFromValue($document['plateValue'] ?? null, $paths);
            $this->collectRelativeAssetPathsFromValue($document['visuals'] ?? null, $paths);
        }

        ksort($paths);

        return array_keys($paths);
    }

    /**
     * @param array<string, true> $paths
     */
    private function collectRelativeAssetPathsFromValue(mixed $value, array &$paths): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::URL_FIELD_NAMES, true) && is_string($item)) {
                $this->addRelativeAssetPath($item, $paths);
                continue;
            }

            if (is_array($item)) {
                $this->collectRelativeAssetPathsFromValue($item, $paths);
            }
        }
    }

    /**
     * @param array<string, true> $paths
     */
    private function addRelativeAssetPath(string $value, array &$paths): void
    {
        $path = $this->normalizeZipAssetPath($value);

        if (null === $path || ! $this->hasUploadableExtension($path)) {
            return;
        }

        $paths[$path] = true;
    }

    private function normalizeZipAssetPath(string $value): ?string
    {
        $value = trim($value);

        if ('' === $value || preg_match('#^(?:https?:|data:|blob:|mailto:|tel:|#)#i', $value) || str_starts_with($value, '/')) {
            return null;
        }

        $path = preg_split('/[?#]/', $value, 2)[0] ?? '';
        $path = preg_replace('#^\./+#', '', str_replace('\\', '/', $path));

        if (! is_string($path) || '' === $path) {
            return null;
        }

        if (! $this->isSafeZipEntryName($path)) {
            throw new InvalidArgumentException(__('ZIP package contains an unsafe media path.', 'contents-editor'));
        }

        return $path;
    }

    private function hasUploadableExtension(string $path): bool
    {
        if (! function_exists('wp_check_filetype')) {
            return '' !== pathinfo($path, PATHINFO_EXTENSION);
        }

        $filetype = wp_check_filetype($path);

        return ! empty($filetype['type']);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function importZipAsset(object $zip, string $base_dir, string $asset_path, ?array $metadata): string
    {
        $entry = $this->resolveZipAssetEntry($zip, $base_dir, $asset_path);
        $bytes = $this->zipEntryContent($zip, $entry);

        if (! is_string($bytes)) {
            throw new InvalidArgumentException(sprintf(__('Media file "%s" could not be read from the ZIP package.', 'contents-editor'), $asset_path));
        }

        $this->loadMediaIncludes();

        $filename = sanitize_file_name(basename($asset_path));
        $tmp_file = wp_tempnam($filename);

        if (! is_string($tmp_file) || '' === $tmp_file) {
            throw new InvalidArgumentException(__('Temporary media file could not be created.', 'contents-editor'));
        }

        if (false === file_put_contents($tmp_file, $bytes)) {
            wp_delete_file($tmp_file);
            throw new InvalidArgumentException(__('Temporary media file could not be written.', 'contents-editor'));
        }

        $checked = wp_check_filetype_and_ext($tmp_file, $filename);

        if (empty($checked['type']) || empty($checked['ext'])) {
            wp_delete_file($tmp_file);
            throw new InvalidArgumentException(sprintf(__('Media file "%s" is not an allowed WordPress upload type.', 'contents-editor'), $asset_path));
        }

        $file = [
            'error' => 0,
            'name' => $filename,
            'size' => filesize($tmp_file),
            'tmp_name' => $tmp_file,
            'type' => $checked['type'],
        ];

        $attachment_id = media_handle_sideload(
            $file,
            0,
            $this->assetTitle($metadata, $filename)
        );

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp_file);
            throw new InvalidArgumentException($attachment_id->get_error_message());
        }

        $this->updateAttachmentMetadata((int) $attachment_id, $metadata);

        $url = wp_get_attachment_url((int) $attachment_id);

        if (! is_string($url) || '' === $url) {
            throw new InvalidArgumentException(sprintf(__('Media file "%s" was uploaded but its URL could not be resolved.', 'contents-editor'), $asset_path));
        }

        return $url;
    }

    private function resolveZipAssetEntry(object $zip, string $base_dir, string $asset_path): string
    {
        $candidates = array_values(
            array_unique(
                array_filter(
                    [
                        '' !== $base_dir ? $base_dir . $asset_path : null,
                        $asset_path,
                    ]
                )
            )
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || ! $this->isSafeZipEntryName($candidate)) {
                continue;
            }

            if ($this->zipEntryExists($zip, $candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf(__('Media file "%s" was referenced but not found in the ZIP package.', 'contents-editor'), $asset_path));
    }

    private function loadMediaIncludes(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>|null
     */
    private function assetMetadataForPath(array $package, string $asset_path): ?array
    {
        $documents = isset($package['lessonDocuments']) && is_array($package['lessonDocuments'])
            ? $package['lessonDocuments']
            : [];

        foreach ($documents as $document) {
            if (! is_array($document) || ! isset($document['assets']) || ! is_array($document['assets'])) {
                continue;
            }

            foreach ($document['assets'] as $asset) {
                if (! is_array($asset) || ! isset($asset['url']) || ! is_string($asset['url'])) {
                    continue;
                }

                if ($asset_path === $this->normalizeZipAssetPath($asset['url'])) {
                    return $asset;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function assetTitle(?array $metadata, string $filename): string
    {
        if (is_array($metadata) && isset($metadata['title']) && is_string($metadata['title']) && '' !== trim($metadata['title'])) {
            return sanitize_text_field($metadata['title']);
        }

        return preg_replace('/\.[^.]+$/', '', $filename) ?: $filename;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function updateAttachmentMetadata(int $attachment_id, ?array $metadata): void
    {
        if (! is_array($metadata)) {
            return;
        }

        if (isset($metadata['alt']) && is_string($metadata['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($metadata['alt']));
        }

        $updates = [
            'ID' => $attachment_id,
        ];

        if (isset($metadata['caption']) && is_string($metadata['caption'])) {
            $updates['post_excerpt'] = sanitize_text_field($metadata['caption']);
        }

        $notes = [];

        foreach (['source', 'license'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && '' !== trim($metadata[$key])) {
                $notes[] = ucfirst($key) . ': ' . sanitize_text_field($metadata[$key]);
            }
        }

        if ($notes) {
            $updates['post_content'] = implode("\n", $notes);
        }

        if (count($updates) > 1) {
            wp_update_post($updates);
        }
    }

    /**
     * @param array<string, mixed> $asset_map
     */
    private function replaceAssetReferences(mixed $value, array $asset_map): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $next = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::URL_FIELD_NAMES, true) && is_string($item)) {
                $next[$key] = $this->replaceAssetUrl($item, $asset_map);
                continue;
            }

            $next[$key] = is_array($item) ? $this->replaceAssetReferences($item, $asset_map) : $item;
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $asset_map
     */
    private function replaceAssetUrl(string $value, array $asset_map): string
    {
        try {
            $path = $this->normalizeZipAssetPath($value);
        } catch (InvalidArgumentException) {
            return $value;
        }

        if (null === $path || ! isset($asset_map[$path]) || ! is_string($asset_map[$path])) {
            return $value;
        }

        return $asset_map[$path];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScopedTree(ContentNode $node): array
    {
        if (ContentNodeType::TOPIC === $node->type) {
            return $this->repository->buildTopicTree($node->id);
        }

        if (ContentNodeType::CHAPTER === $node->type) {
            $topic = $this->requireParent($node, ContentNodeType::TOPIC);

            return [
                'topic' => $topic->toArray(),
                'chapters' => [$this->chapterGroup($node)],
            ];
        }

        if (ContentNodeType::SECTION === $node->type) {
            $chapter = $this->requireParent($node, ContentNodeType::CHAPTER);
            $topic = $this->requireParent($chapter, ContentNodeType::TOPIC);

            return [
                'topic' => $topic->toArray(),
                'chapters' => [
                    [
                        'chapter' => $chapter->toArray(),
                        'sections' => [$this->sectionGroup($node)],
                    ],
                ],
            ];
        }

        $section = $this->requireParent($node, ContentNodeType::SECTION);
        $chapter = $this->requireParent($section, ContentNodeType::CHAPTER);
        $topic = $this->requireParent($chapter, ContentNodeType::TOPIC);

        return [
            'topic' => $topic->toArray(),
            'chapters' => [
                [
                    'chapter' => $chapter->toArray(),
                    'sections' => [
                        [
                            'section' => $section->toArray(),
                            'lessons' => [$node->toArray()],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapterGroup(ContentNode $chapter): array
    {
        $sections = array_map(
            fn (ContentNode $section): array => $this->sectionGroup($section),
            $this->repository->listNodes(
                [
                    'type' => ContentNodeType::SECTION->value,
                    'parentId' => $chapter->id,
                ]
            )
        );

        return [
            'chapter' => $chapter->toArray(),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionGroup(ContentNode $section): array
    {
        return [
            'section' => $section->toArray(),
            'lessons' => $this->repository->nodesToArray(
                $this->repository->listNodes(
                    [
                        'type' => ContentNodeType::LESSON->value,
                        'parentId' => $section->id,
                    ]
                )
            ),
        ];
    }

    private function requireParent(ContentNode $node, ContentNodeType $expected_type): ContentNode
    {
        $parent = $node->parentId ? $this->repository->getNode($node->parentId) : null;

        if (! $parent || $expected_type !== $parent->type) {
            throw new InvalidArgumentException(__('목차 부모 구조가 올바르지 않습니다.', 'contents-editor'));
        }

        return $parent;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function assertSupportedPackage(array $package): void
    {
        $kind = $package['kind'] ?? '';

        if (! in_array($kind, [self::PACKAGE_KIND, self::LEGACY_TOPIC_PACKAGE_KIND], true)) {
            throw new InvalidArgumentException(__('지원하지 않는 콘텐츠 패키지입니다.', 'contents-editor'));
        }

        if (! isset($package['tree']) || ! is_array($package['tree'])) {
            throw new InvalidArgumentException(__('패키지에 목차 정보가 없습니다.', 'contents-editor'));
        }
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function previewImportPlan(array $package): array
    {
        $tree = $package['tree'];
        $documents = isset($package['lessonDocuments']) && is_array($package['lessonDocuments'])
            ? $package['lessonDocuments']
            : [];
        $created = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $reused = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $conflicts = [];

        $source_topic = $this->readNode($tree['topic'] ?? null, ContentNodeType::TOPIC);
        $topic_match = $this->findMatchingNodeMatch($source_topic, ContentNodeType::TOPIC, null);
        $topic = $topic_match ? $topic_match['node'] : null;

        if ($topic instanceof ContentNode) {
            ++$reused['topics'];
        } else {
            ++$created['topics'];
        }

        $chapters = $tree['chapters'] ?? [];
        $chapters = is_array($chapters) ? $chapters : [];

        foreach ($chapters as $chapter_group) {
            if (! is_array($chapter_group)) {
                continue;
            }

            $source_chapter = $this->readNode($chapter_group['chapter'] ?? null, ContentNodeType::CHAPTER);
            $chapter = null;

            if ($topic instanceof ContentNode) {
                $chapter_match = $this->findMatchingNodeMatch($source_chapter, ContentNodeType::CHAPTER, $topic->id);
                $chapter = $chapter_match ? $chapter_match['node'] : null;
            }

            if ($chapter instanceof ContentNode) {
                ++$reused['chapters'];
            } else {
                ++$created['chapters'];
            }

            $sections = $chapter_group['sections'] ?? [];
            $sections = is_array($sections) ? $sections : [];

            foreach ($sections as $section_group) {
                if (! is_array($section_group)) {
                    continue;
                }

                $source_section = $this->readNode($section_group['section'] ?? null, ContentNodeType::SECTION);
                $section = null;

                if ($chapter instanceof ContentNode) {
                    $section_match = $this->findMatchingNodeMatch($source_section, ContentNodeType::SECTION, $chapter->id);
                    $section = $section_match ? $section_match['node'] : null;
                }

                if ($section instanceof ContentNode) {
                    ++$reused['sections'];
                } else {
                    ++$created['sections'];
                }

                $lessons = $section_group['lessons'] ?? [];
                $lessons = is_array($lessons) ? $lessons : [];

                foreach ($lessons as $source_lesson) {
                    $source_lesson = $this->readNode($source_lesson, ContentNodeType::LESSON);
                    ++$created['lessons'];

                    if (! ($section instanceof ContentNode)) {
                        continue;
                    }

                    $lesson_match = $this->findMatchingNodeMatch($source_lesson, ContentNodeType::LESSON, $section->id);

                    if (! $lesson_match) {
                        continue;
                    }

                    /** @var ContentNode $existing_lesson */
                    $existing_lesson = $lesson_match['node'];
                    $incoming_document = $this->documentForLesson($documents, (string) $source_lesson['id']);
                    $existing_document = $this->repository->getLessonDocument($existing_lesson->id)->toArray();

                    $conflicts[] = [
                        'type' => 'lesson',
                        'path' => [
                            (string) $source_topic['title'],
                            (string) $source_chapter['title'],
                            (string) $source_section['title'],
                            (string) $source_lesson['title'],
                        ],
                        'matchBy' => $lesson_match['matchBy'],
                        'incoming' => $this->nodePreviewArray($source_lesson, ContentNodeType::LESSON, $section->id),
                        'existing' => $existing_lesson->toArray(),
                        'incomingSummary' => $this->documentSummary($incoming_document),
                        'existingSummary' => $this->documentSummary($existing_document),
                    ];
                }
            }
        }

        return [
            'topic' => $topic instanceof ContentNode
                ? $topic->toArray()
                : $this->nodePreviewArray($source_topic, ContentNodeType::TOPIC, null),
            'created' => $created,
            'reused' => $reused,
            'conflicts' => $conflicts,
        ];
    }

    private function readLessonConflictPolicy(mixed $policy): string
    {
        if (! is_string($policy) || '' === $policy) {
            return self::LESSON_CONFLICT_CREATE_NEW;
        }

        if (! in_array($policy, [self::LESSON_CONFLICT_CREATE_NEW, self::LESSON_CONFLICT_REPLACE, self::LESSON_CONFLICT_SKIP], true)) {
            throw new InvalidArgumentException(__('지원하지 않는 중복 레슨 처리 방식입니다.', 'contents-editor'));
        }

        return $policy;
    }

    /**
     * @return array<string, string>
     */
    private function readLessonConflictPolicyMap(mixed $policies): array
    {
        if (! is_array($policies)) {
            return [];
        }

        $normalized = [];

        foreach ($policies as $source_lesson_id => $policy) {
            $source_lesson_id = trim((string) $source_lesson_id);

            if ('' === $source_lesson_id) {
                continue;
            }

            $normalized[$source_lesson_id] = $this->readLessonConflictPolicy($policy);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{topics: int, chapters: int, sections: int, lessons: int} $created
     * @param array{topics: int, chapters: int, sections: int, lessons: int} $reused
     */
    private function importContainerNode(
        array $node,
        ContentNodeType $type,
        ?string $parent_id,
        array &$created,
        array &$reused,
        ?int &$next_order = null
    ): ContentNode {
        $existing = $this->findMatchingContainerNode($node, $type, $parent_id);
        $counter_key = $this->counterKey($type);

        if ($existing) {
            ++$reused[$counter_key];

            return $existing;
        }

        $created_node = $this->createImportedNode($node, $type, $parent_id, $next_order);
        ++$created[$counter_key];

        if (null !== $next_order) {
            ++$next_order;
        }

        return $created_node;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function findMatchingContainerNode(array $node, ContentNodeType $type, ?string $parent_id): ?ContentNode
    {
        $match = $this->findMatchingNodeMatch($node, $type, $parent_id);

        return $match ? $match['node'] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{node: ContentNode, matchBy: 'slug'|'title'}|null
     */
    private function findMatchingNodeMatch(array $node, ContentNodeType $type, ?string $parent_id): ?array
    {
        $slug = sanitize_title((string) ($node['slug'] ?? ''));
        $title = trim((string) ($node['title'] ?? ''));
        $candidates = $this->repository->listNodes(
            [
                'type' => $type->value,
                'parentId' => $parent_id,
            ]
        );

        if ('' !== $slug) {
            foreach ($candidates as $candidate) {
                if ($slug === $candidate->slug) {
                    return [
                        'node' => $candidate,
                        'matchBy' => 'slug',
                    ];
                }
            }
        }

        if ('' !== $title) {
            foreach ($candidates as $candidate) {
                if ($title === $candidate->title) {
                    return [
                        'node' => $candidate,
                        'matchBy' => 'title',
                    ];
                }
            }
        }

        return null;
    }

    private function nextOrderForParent(ContentNodeType $type, ?string $parent_id): int
    {
        $nodes = $this->repository->listNodes(
            [
                'type' => $type->value,
                'parentId' => $parent_id,
                'includeArchived' => true,
            ]
        );

        $max_order = -1;

        foreach ($nodes as $node) {
            $max_order = max($max_order, $node->order);
        }

        return $max_order + 1;
    }

    private function counterKey(ContentNodeType $type): string
    {
        return match ($type) {
            ContentNodeType::TOPIC => 'topics',
            ContentNodeType::CHAPTER => 'chapters',
            ContentNodeType::SECTION => 'sections',
            ContentNodeType::LESSON => 'lessons',
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function createImportedNode(array $node, ContentNodeType $type, ?string $parent_id, ?int $order = null): ContentNode
    {
        return $this->repository->createNode(
            [
                'type' => $type->value,
                'parentId' => $parent_id,
                'title' => (string) $node['title'],
                'slug' => (string) ($node['slug'] ?? $node['title']),
                'order' => $order ?? (int) ($node['order'] ?? 0),
                'status' => ContentStatus::DRAFT->value,
                'contentType' => (string) ($node['contentType'] ?? 'default'),
                'schemaVersion' => (string) ($node['schemaVersion'] ?? self::PACKAGE_VERSION),
                'metadata' => is_array($node['metadata'] ?? null) ? $node['metadata'] : [],
            ]
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function updateImportedNode(ContentNode $existing, array $node, ?string $parent_id): ContentNode
    {
        return $this->repository->updateNode(
            $existing->id,
            [
                'parentId' => $parent_id,
                'title' => (string) $node['title'],
                'slug' => (string) ($node['slug'] ?? $node['title']),
                'order' => $existing->order,
                'status' => ContentStatus::DRAFT->value,
                'contentType' => (string) ($node['contentType'] ?? 'default'),
                'schemaVersion' => (string) ($node['schemaVersion'] ?? self::PACKAGE_VERSION),
                'metadata' => is_array($node['metadata'] ?? null) ? $node['metadata'] : [],
            ]
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function nodePreviewArray(array $node, ContentNodeType $type, ?string $parent_id): array
    {
        return [
            'id' => (string) $node['id'],
            'slug' => sanitize_title((string) ($node['slug'] ?? $node['title'])),
            'title' => (string) $node['title'],
            'type' => $type->value,
            'status' => ContentStatus::DRAFT->value,
            'parentId' => $parent_id,
            'order' => (int) ($node['order'] ?? 0),
            'contentType' => (string) ($node['contentType'] ?? 'default'),
            'schemaVersion' => (string) ($node['schemaVersion'] ?? self::PACKAGE_VERSION),
            'metadata' => is_array($node['metadata'] ?? null) ? $node['metadata'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function saveImportedLessonDocument(string $lesson_id, array $document): void
    {
        $this->repository->saveLessonDocument(
            $lesson_id,
            [
                'plateValue' => is_array($document['plateValue'] ?? null) ? $document['plateValue'] : [],
                'assets' => is_array($document['assets'] ?? null) ? $document['assets'] : [],
                'visuals' => is_array($document['visuals'] ?? null) ? $document['visuals'] : [],
                'schemaVersion' => (string) ($document['schemaVersion'] ?? self::PACKAGE_VERSION),
            ]
        );
    }

    /**
     * @param mixed $node
     * @return array<string, mixed>
     */
    private function readNode(mixed $node, ContentNodeType $type): array
    {
        if (! is_array($node)) {
            throw new InvalidArgumentException(__('패키지 목차 구조가 올바르지 않습니다.', 'contents-editor'));
        }

        if (($node['type'] ?? '') !== $type->value) {
            throw new InvalidArgumentException(__('패키지 목차 단계가 올바르지 않습니다.', 'contents-editor'));
        }

        if (empty($node['id']) || empty($node['title'])) {
            throw new InvalidArgumentException(__('패키지 목차 항목에 ID 또는 제목이 없습니다.', 'contents-editor'));
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<int, array<string, mixed>>
     */
    private function lessonNodesFromTree(array $tree): array
    {
        $lessons = [];
        $chapters = is_array($tree['chapters'] ?? null) ? $tree['chapters'] : [];

        foreach ($chapters as $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $sections = is_array($chapter['sections'] ?? null) ? $chapter['sections'] : [];

            foreach ($sections as $section) {
                if (! is_array($section)) {
                    continue;
                }

                $section_lessons = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];

                foreach ($section_lessons as $lesson) {
                    if (is_array($lesson)) {
                        $lessons[] = $lesson;
                    }
                }
            }
        }

        return $lessons;
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private function documentSummary(?array $document): string
    {
        if (! $document) {
            return '';
        }

        $text = $this->plainTextFromValue($document['plateValue'] ?? null);

        if ('' === $text) {
            $visuals = is_array($document['visuals'] ?? null) ? count($document['visuals']) : 0;
            $assets = is_array($document['assets'] ?? null) ? count($document['assets']) : 0;

            return sprintf(__('본문 없음, 시각화 %1$d개, 미디어 %2$d개', 'contents-editor'), $visuals, $assets);
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, 140) : substr($text, 0, 140);
    }

    private function plainTextFromValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $key => $item) {
            if ('text' === $key && is_string($item)) {
                $parts[] = $item;
                continue;
            }

            if (is_array($item)) {
                $part = $this->plainTextFromValue($item);

                if ('' !== $part) {
                    $parts[] = $part;
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * @param array<string|int, mixed> $documents
     * @return array<string, mixed>|null
     */
    private function documentForLesson(array $documents, string $source_lesson_id): ?array
    {
        if (isset($documents[$source_lesson_id]) && is_array($documents[$source_lesson_id])) {
            return $documents[$source_lesson_id];
        }

        foreach ($documents as $document) {
            if (is_array($document) && (string) ($document['lessonId'] ?? '') === $source_lesson_id) {
                return $document;
            }
        }

        return null;
    }
}
