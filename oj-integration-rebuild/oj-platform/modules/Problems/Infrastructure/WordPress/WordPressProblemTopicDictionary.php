<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopicDictionaryVersion;
use DomainException;
use RuntimeException;

final class WordPressProblemTopicDictionary implements ProblemTopicDictionary
{
    public const TAXONOMY = 'oj_problem_topic';

    private const META_ALIASES = '_ojp_topic_aliases';
    private const META_ACTIVE = '_ojp_topic_active';
    private const META_CREATED_BY = '_ojp_topic_created_by';
    private const META_CREATED_AT = '_ojp_topic_created_at';

    /**
     * @param list<array<string, mixed>> $seed
     */
    public function __construct(private readonly array $seed)
    {
    }

    public function registerTaxonomy(): void
    {
        if (! function_exists('register_taxonomy')) {
            return;
        }

        register_taxonomy(
            self::TAXONOMY,
            [],
            [
                'labels' => [
                    'name' => '문제 주제',
                    'singular_name' => '문제 주제',
                ],
                'public' => false,
                'show_ui' => false,
                'show_in_rest' => false,
                'hierarchical' => true,
                'rewrite' => false,
                'query_var' => false,
            ]
        );
    }

    public function bootstrap(): void
    {
        $this->registerTaxonomy();
        $this->ensureSeeded();
    }

    public function ensureSeeded(): void
    {
        $this->assertFunctions();
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'slug' => array_values(array_map(static fn (array $topic): string => (string) $topic['key'], $this->seed)),
            'update_term_meta_cache' => true,
        ]);
        if (! is_array($terms)) {
            throw new RuntimeException('기본 문제 주제를 확인하지 못했습니다.');
        }
        $existingTerms = [];
        foreach ($terms as $term) {
            if (is_object($term)) { $existingTerms[(string) ($term->slug ?? '')] = $term; }
        }
        $categories = array_values(array_filter(
            $this->seed,
            static fn (array $topic): bool => ($topic['category'] ?? null) === null
        ));
        $details = array_values(array_filter(
            $this->seed,
            static fn (array $topic): bool => is_string($topic['category'] ?? null)
        ));
        foreach (array_merge($categories, $details) as $topic) {
            $key = (string) ($topic['key'] ?? '');
            $aliases = (array) ($topic['aliases'] ?? []);
            $existing = isset($existingTerms[$key]) ? $this->fromTerm($existingTerms[$key]) : null;
            if ($existing !== null) {
                if ($existing->aliases() !== $this->normalizeAliases($aliases)) {
                    $this->reconcileAliases($key, $aliases);
                }
                continue;
            }
            $category = $topic['category'] ?? null;
            if (is_string($category)) {
                $this->createDetail(
                    $category,
                    $key,
                    (string) ($topic['label'] ?? ''),
                    $aliases,
                    0
                );
            } else {
                $this->createCategory(
                    $key,
                    (string) ($topic['label'] ?? ''),
                    $aliases,
                    0
                );
            }
        }
    }

    /**
     * @param list<string> $seedAliases
     */
    private function reconcileAliases(string $key, array $seedAliases): void
    {
        $term = get_term_by('slug', $key, self::TAXONOMY);
        if (! is_object($term)) {
            return;
        }
        update_term_meta(
            (int) ($term->term_id ?? 0),
            self::META_ALIASES,
            $this->normalizeAliases($seedAliases)
        );
    }

    public function all(): array
    {
        $this->assertFunctions();
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC',
        ]);
        if (is_wp_error($terms)) {
            throw new RuntimeException('문제 주제 사전을 읽지 못했습니다.');
        }
        if (! is_array($terms)) {
            return [];
        }

        $byId = [];
        foreach ($terms as $term) {
            if (is_object($term)) {
                $byId[(int) ($term->term_id ?? 0)] = (string) ($term->slug ?? '');
            }
        }

        $topics = [];
        foreach ($terms as $term) {
            if (! is_object($term)) {
                continue;
            }
            $termId = (int) ($term->term_id ?? 0);
            $parentId = (int) ($term->parent ?? 0);
            $aliases = get_term_meta($termId, self::META_ALIASES, true);
            $topics[] = new ProblemTopic(
                (string) ($term->slug ?? ''),
                (string) ($term->name ?? ''),
                $parentId > 0 ? ($byId[$parentId] ?? null) : null,
                $this->normalizeAliases(is_array($aliases) ? $aliases : []),
                (string) get_term_meta($termId, self::META_ACTIVE, true) !== '0'
            );
        }

        usort($topics, static function (ProblemTopic $left, ProblemTopic $right): int {
            if ($left->isCategory() !== $right->isCategory()) {
                return $left->isCategory() ? -1 : 1;
            }

            return strcmp($left->key(), $right->key());
        });

        return $topics;
    }

    public function find(string $key): ?ProblemTopic
    {
        $this->assertFunctions();
        $term = get_term_by('slug', $key, self::TAXONOMY);
        if (! is_object($term)) {
            return null;
        }
        return $this->fromTerm($term);
    }

    private function fromTerm(object $term): ProblemTopic
    {
        $termId = (int) ($term->term_id ?? 0);
        $parentId = (int) ($term->parent ?? 0);
        $parentKey = null;
        if ($parentId > 0) {
            $parent = get_term($parentId, self::TAXONOMY);
            if (is_object($parent)) {
                $parentKey = (string) ($parent->slug ?? '');
            }
        }
        $aliases = get_term_meta($termId, self::META_ALIASES, true);

        return new ProblemTopic(
            (string) ($term->slug ?? ''),
            (string) ($term->name ?? ''),
            $parentKey,
            $this->normalizeAliases(is_array($aliases) ? $aliases : []),
            (string) get_term_meta($termId, self::META_ACTIVE, true) !== '0'
        );
    }

    public function assertSelectableDetails(array $detailKeys): void
    {
        $detailKeys = array_values(array_unique($detailKeys));
        if (count($detailKeys) > 3) {
            throw new DomainException('활성 세부 주제는 최대 3개까지 선택할 수 있습니다.');
        }
        foreach ($detailKeys as $detailKey) {
            $topic = $this->find($detailKey);
            if (
                $topic === null
                || $topic->isCategory()
                || ! $topic->isActive()
            ) {
                throw new DomainException('선택할 수 없는 문제 주제가 포함되어 있습니다.');
            }
            $category = $this->find((string) $topic->categoryKey());
            if ($category === null || ! $category->isCategory()) {
                throw new DomainException('문제 주제는 분야와 세부 주제의 2단계여야 합니다.');
            }
        }
    }

    public function version(): string
    {
        return ProblemTopicDictionaryVersion::fromTopics($this->all());
    }

    public function createCategory(
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        $this->insert($key, $label, null, $aliases, $actorUserId);
    }

    public function createDetail(
        string $categoryKey,
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        $category = $this->find($categoryKey);
        if ($category === null || ! $category->isCategory()) {
            throw new DomainException('상위 분야를 찾을 수 없습니다.');
        }
        $this->insert($key, $label, $categoryKey, $aliases, $actorUserId);
    }

    /**
     * @param list<string> $aliases
     */
    private function insert(
        string $key,
        string $label,
        ?string $categoryKey,
        array $aliases,
        int $actorUserId
    ): void {
        $topic = new ProblemTopic(
            $key,
            trim($label),
            $categoryKey,
            $this->normalizeAliases($aliases)
        );
        $this->assertUnique($topic);
        $parentId = 0;
        if ($categoryKey !== null) {
            $parent = get_term_by('slug', $categoryKey, self::TAXONOMY);
            if (! is_object($parent) || (int) ($parent->parent ?? 0) !== 0) {
                throw new DomainException('세부 주제의 상위 항목은 최상위 분야여야 합니다.');
            }
            $parentId = (int) ($parent->term_id ?? 0);
        }

        $result = wp_insert_term(
            $topic->label(),
            self::TAXONOMY,
            ['slug' => $topic->key(), 'parent' => $parentId]
        );
        if (is_wp_error($result) || ! is_array($result)) {
            throw new RuntimeException('문제 주제를 추가하지 못했습니다.');
        }
        $termId = (int) ($result['term_id'] ?? 0);
        update_term_meta($termId, self::META_ALIASES, $topic->aliases());
        update_term_meta($termId, self::META_ACTIVE, '1');
        update_term_meta($termId, self::META_CREATED_BY, $actorUserId);
        update_term_meta($termId, self::META_CREATED_AT, gmdate('c'));
    }

    private function assertUnique(ProblemTopic $candidate): void
    {
        $candidateTokens = array_merge(
            [$candidate->key(), $candidate->label()],
            $candidate->aliases()
        );
        $normalizedCandidate = array_map([$this, 'normalizeToken'], $candidateTokens);
        foreach ($this->all() as $topic) {
            $existingTokens = array_merge(
                [$topic->key(), $topic->label()],
                $topic->aliases()
            );
            foreach ($existingTokens as $existingToken) {
                if (in_array($this->normalizeToken($existingToken), $normalizedCandidate, true)) {
                    throw new DomainException('이미 사용 중인 주제 키·이름·별칭이 있습니다.');
                }
            }
        }
    }

    /**
     * @param array<mixed> $aliases
     * @return list<string>
     */
    private function normalizeAliases(array $aliases): array
    {
        $normalized = [];
        foreach ($aliases as $alias) {
            if (! is_scalar($alias)) {
                continue;
            }
            $value = trim((string) $alias);
            if ($value !== '') {
                $normalized[$this->normalizeToken($value)] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeToken(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/[\s_-]+/u', '-', $value) ?? $value;
    }

    private function assertFunctions(): void
    {
        foreach (
            [
                'get_terms',
                'get_term_by',
                'get_term',
                'get_term_meta',
                'wp_insert_term',
                'update_term_meta',
                'is_wp_error',
            ] as $function
        ) {
            if (! function_exists($function)) {
                throw new RuntimeException('WordPress 문제 주제 API를 사용할 수 없습니다.');
            }
        }
    }
}
