<?php
/**
 * Converts the bundled legacy lesson blocks into editable Plate nodes.
 *
 * @package ReadContentsLibrary\Editor\Migration
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Migration;

final class LegacyBlockConverter
{
    /** @var array<string, array{source: string, license: string}>|null */
    private static ?array $image_source_index = null;

    /**
     * @return array<string, array{source: string, license: string}>
     */
    private static function imageSourceIndex(): array
    {
        if (null !== self::$image_source_index) {
            return self::$image_source_index;
        }

        $index = [];
        $path = READCONTENTS_LIBRARY_PATH . 'data/image_sources.json';

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $topic_id = (string) ($entry['topicId'] ?? '');
                    $file = (string) ($entry['file'] ?? '');

                    if ('' === $topic_id || '' === $file) {
                        continue;
                    }

                    $index[$topic_id . '::' . $file] = [
                        'source' => (string) ($entry['source'] ?? ''),
                        'license' => (string) ($entry['license'] ?? ''),
                    ];
                }
            }
        }

        self::$image_source_index = $index;

        return $index;
    }

    /** @var array<string, list<string>>|null */
    private static ?array $content_source_index = null;

    /** @var array<string, array{title: string, organization: string, url: string}>|null */
    private static ?array $source_catalog_index = null;

    /**
     * @return array<string, list<string>>
     */
    private static function contentSourceIndex(): array
    {
        if (null !== self::$content_source_index) {
            return self::$content_source_index;
        }

        $index = [];
        $path = READCONTENTS_LIBRARY_PATH . 'data/fact_check_report.json';

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $lessons = is_array($decoded) ? ($decoded['lessons'] ?? null) : null;

            if (is_array($lessons)) {
                foreach ($lessons as $lesson) {
                    if (! is_array($lesson)) {
                        continue;
                    }

                    $topic_id = (string) ($lesson['topicId'] ?? '');
                    $sources = $lesson['sources'] ?? null;

                    if ('' === $topic_id || ! is_array($sources)) {
                        continue;
                    }

                    $index[$topic_id] = array_values(array_filter(array_map('strval', $sources)));
                }
            }
        }

        self::$content_source_index = $index;

        return $index;
    }

    /**
     * @return array<string, array{title: string, organization: string, url: string}>
     */
    private static function sourceCatalogIndex(): array
    {
        if (null !== self::$source_catalog_index) {
            return self::$source_catalog_index;
        }

        $index = [];
        $path = READCONTENTS_LIBRARY_PATH . 'data/source_catalog.json';

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $id = (string) ($entry['id'] ?? '');

                    if ('' === $id) {
                        continue;
                    }

                    $index[$id] = [
                        'title' => (string) ($entry['title'] ?? ''),
                        'organization' => (string) ($entry['organization'] ?? ''),
                        'url' => (string) ($entry['url'] ?? ''),
                    ];
                }
            }
        }

        self::$source_catalog_index = $index;

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contentSourceBlocks(string $topic_id): array
    {
        $source_ids = self::contentSourceIndex()[$topic_id] ?? [];

        if (! $source_ids) {
            return [];
        }

        $catalog = self::sourceCatalogIndex();
        $items = [];

        foreach ($source_ids as $source_id) {
            $entry = $catalog[$source_id] ?? null;

            if (null === $entry || '' === $entry['title']) {
                continue;
            }

            $label = $entry['title'];

            if ('' !== $entry['organization']) {
                $label .= ' — ' . $entry['organization'];
            }

            $children = [['text' => $label]];

            if ('' !== $entry['url']) {
                $children = [
                    [
                        'type' => 'a',
                        'url' => $entry['url'],
                        'children' => [['text' => $label]],
                    ],
                ];
            }

            $items[] = [
                'type' => 'li',
                'children' => $children,
            ];
        }

        if (! $items) {
            return [];
        }

        return [
            ['type' => 'hr', 'children' => [['text' => '']]],
            self::textBlock('h2', '이 레슨이 참고한 자료'),
            [
                'type' => 'ul',
                'children' => $items,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{plateValue: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>, visuals: array<int, array<string, mixed>>, schemaVersion: string}
     */
    public static function convert(array $blocks, string $topic_id): array
    {
        $plate_value = [];
        $assets = [];
        $visuals = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');

            if (in_array($type, ['h1', 'h2', 'h3', 'p', 'blockquote'], true)) {
                $plate_value[] = self::textBlock($type, (string) ($block['text'] ?? ''));
                continue;
            }

            if ('code' === $type) {
                $lines = preg_split('/\r\n|\r|\n/', (string) ($block['text'] ?? ''));
                $plate_value[] = [
                    'type' => 'code_block',
                    'lang' => (string) ($block['language'] ?? ''),
                    'children' => array_map(
                        static fn (string $line): array => self::textBlock('code_line', $line),
                        is_array($lines) ? $lines : ['']
                    ),
                ];
                continue;
            }

            if ('hr' === $type) {
                $plate_value[] = [
                    'type' => 'hr',
                    'children' => [['text' => '']],
                ];
                continue;
            }

            if ('img' === $type) {
                $image = self::imageBlock($block, $topic_id, $index);

                if ($image) {
                    $plate_value[] = $image['node'];
                    $assets[] = $image['asset'];
                }

                continue;
            }

            if ('visualization' === $type) {
                $visual = self::visualizationBlock($block, $topic_id, $index);

                if ($visual) {
                    $plate_value[] = $visual['node'];
                    $visuals[] = $visual['document'];
                }

                continue;
            }

            if ('table' === $type) {
                $table = self::tableBlock($block);

                if ($table) {
                    $plate_value[] = $table;
                }

                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));

            if ('' !== $text) {
                $plate_value[] = self::textBlock('p', $text);
            }
        }

        foreach (self::contentSourceBlocks($topic_id) as $reference_block) {
            $plate_value[] = $reference_block;
        }

        if (! $plate_value) {
            $plate_value[] = self::textBlock('p', '');
        }

        return [
            'plateValue' => $plate_value,
            'assets' => $assets,
            'visuals' => $visuals,
            'schemaVersion' => '1.0.0',
        ];
    }

    /** @return array<string, mixed> */
    private static function textBlock(string $type, string $text): array
    {
        return [
            'type' => $type,
            'children' => [
                [
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array{node: array<string, mixed>, asset: array<string, mixed>}|null
     */
    private static function imageBlock(array $block, string $topic_id, int $index): ?array
    {
        $file = trim((string) ($block['file'] ?? ''));

        if ('' === $file) {
            return null;
        }

        $url = READCONTENTS_LIBRARY_URL
            . 'assets/media/'
            . rawurlencode($topic_id)
            . '/'
            . rawurlencode($file);
        $alt = (string) ($block['alt'] ?? '');
        $caption = (string) ($block['caption'] ?? '');
        $asset_id = sprintf('legacy-image-%s-%d', $topic_id, $index + 1);
        $provenance = self::imageSourceIndex()[$topic_id . '::' . $file] ?? null;

        $node = [
            'type' => 'img',
            'url' => $url,
            'alt' => $alt,
            'caption' => [['text' => $caption]],
            'children' => [['text' => '']],
        ];
        $asset = [
            'id' => $asset_id,
            'kind' => 'image',
            'title' => '' !== trim($alt) ? $alt : $file,
            'url' => $url,
            'alt' => $alt,
            'caption' => $caption,
        ];

        if (null !== $provenance && '' !== $provenance['source']) {
            $node['source'] = $provenance['source'];
            $node['license'] = $provenance['license'];
            $asset['source'] = $provenance['source'];
            $asset['license'] = $provenance['license'];
        }

        return [
            'node' => $node,
            'asset' => $asset,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array{node: array<string, mixed>, document: array<string, mixed>}|null
     */
    private static function visualizationBlock(array $block, string $topic_id, int $index): ?array
    {
        $code = trim((string) ($block['dsl'] ?? ''));

        if ('' === $code) {
            return null;
        }

        $legacy_type = trim((string) ($block['visualType'] ?? 'flowchart'));
        $visual_type = 'concept-map' === $legacy_type ? 'network' : $legacy_type;
        $visual_id = sprintf('legacy-visual-%s-%d', $topic_id, $index + 1);
        $document = [
            'visualId' => $visual_id,
            'schemaVersion' => '1.0.0',
            'visualType' => '' !== $visual_type ? $visual_type : 'flowchart',
            'title' => (string) ($block['title'] ?? ''),
            'source' => [
                'format' => 'algoviz-dsl',
                'code' => $code,
            ],
            'model' => [
                'viewport' => [
                    'x' => 0,
                    'y' => 0,
                    'zoom' => 1,
                ],
                'nodes' => [],
                'edges' => [],
                'annotations' => [],
                'steps' => [],
            ],
            'assets' => [],
            'metadata' => [
                'renderer' => 'contents-editor-visualization',
                'viewMode' => 'render',
                'legacyVisualType' => $legacy_type,
            ],
        ];

        return [
            'node' => [
                'type' => 'visualization',
                'data' => $document,
                'children' => [['text' => '']],
            ],
            'document' => $document,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function tableBlock(array $block): ?array
    {
        $rows = $block['rows'] ?? null;

        if (! is_array($rows) || ! $rows) {
            return null;
        }

        $headers = $block['headers'] ?? null;
        if (is_array($headers) && $headers) {
            $rows = array_merge([$headers], array_values($rows));
        }

        $children = [];

        foreach ($rows as $row_index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = [];
            $cell_type = 0 === $row_index ? 'th' : 'td';

            foreach ($row as $cell) {
                $cells[] = [
                    'type' => $cell_type,
                    'children' => [self::textBlock('p', (string) $cell)],
                ];
            }

            if ($cells) {
                $children[] = [
                    'type' => 'tr',
                    'children' => $cells,
                ];
            }
        }

        return $children
            ? [
                'type' => 'table',
                'children' => $children,
            ]
            : null;
    }
}
