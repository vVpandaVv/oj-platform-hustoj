<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemTopicDictionary;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
Autoloader::register($root);

$terms = [];
$seed = [];
for ($i = 1; $i <= 93; ++$i) {
    $key = 'topic-' . $i;
    $seed[] = ['key' => $key, 'label' => $key, 'category' => $i === 1 ? null : 'topic-1', 'aliases' => []];
    $terms[$i] = (object) ['term_id' => $i, 'slug' => $key, 'name' => $key, 'parent' => $i === 1 ? 0 : 1];
}
$bulkReads = 0;
$singleReads = 0;
$writes = 0;
function get_terms(array $args): array {
    ++$GLOBALS['bulkReads'];
    if (isset($args['slug']) && ($args['update_term_meta_cache'] ?? false) !== true) { throw new RuntimeException('Metadata must be primed.'); }
    return array_values($GLOBALS['terms']);
}
function get_term_by(string $field, string $key, string $taxonomy): object|false {
    ++$GLOBALS['singleReads'];
    foreach ($GLOBALS['terms'] as $term) { if ($term->slug === $key) { return $term; } }
    return false;
}
function get_term(int $id, string $taxonomy): object|false { return $GLOBALS['terms'][$id] ?? false; }
function get_term_meta(int $id, string $key, bool $single): mixed { return $GLOBALS['metadata'][$id][$key] ?? ($key === '_ojp_topic_aliases' ? [] : '1'); }
function wp_insert_term(string $name, string $taxonomy, array $args): array {
    ++$GLOBALS['writes'];
    $id = max(array_keys($GLOBALS['terms'])) + 1;
    $GLOBALS['terms'][$id] = (object) ['term_id' => $id, 'slug' => $args['slug'], 'name' => $name, 'parent' => $args['parent']];
    return ['term_id' => $id];
}
function update_term_meta(int $id, string $key, mixed $value): bool { ++$GLOBALS['writes']; $GLOBALS['metadata'][$id][$key] = $value; return true; }
function is_wp_error(mixed $value): bool { return false; }
function register_taxonomy(string $name, array $objects, array $args): void {}

for ($request = 0; $request < 36; ++$request) {
    (new WordPressProblemTopicDictionary($seed))->bootstrap();
}
if ($bulkReads !== 36 || $singleReads !== 0 || $writes !== 0) {
    throw new RuntimeException(json_encode(compact('bulkReads', 'singleReads', 'writes'), JSON_THROW_ON_ERROR));
}
unset($terms[93]);
(new WordPressProblemTopicDictionary($seed))->bootstrap();
if (count($terms) !== 93 || $writes !== 5) { throw new RuntimeException('Missing seed must still be created.'); }
$seed[1]['aliases'] = ['new-alias'];
(new WordPressProblemTopicDictionary($seed))->bootstrap();
if (get_term_meta(2, '_ojp_topic_aliases', true) !== ['new-alias']) { throw new RuntimeException('Alias reconciliation must remain active.'); }
fwrite(STDOUT, 'Topic bootstrap cost: 36 independent invocations, 36 bulk reads, 0 per-topic lookups, 0 writes (not a server concurrency test).' . PHP_EOL);
