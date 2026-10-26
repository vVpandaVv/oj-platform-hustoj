<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

/** Apply visibility before search, facets, counts and pagination. */
final class Material_Catalog {
    public static function page(array $materials, array $group_map, array $query, callable $can_view, bool $console = false, ?int $now = null, int $per_page = 12): array {
        $now ??= time();
        $filters = array();
        foreach (array('q', 'type', 'category', 'tag', 'group', 'status') as $key) {
            $filters[$key] = isset($query[$key]) && is_string($query[$key]) ? trim($query[$key]) : '';
        }
        if (! $console) $filters['status'] = '';
        $visible = $categories = $tags = $groups = array();
        $decisions = array();
        $authorized = static function (array $ids) use ($can_view, &$decisions): bool {
            sort($ids, SORT_STRING);
            $key = json_encode($ids);
            return $decisions[$key] ??= (bool) $can_view($ids);
        };
        foreach ($materials as $material) {
            $ids = $group_map[$material->id] ?? array();
            if ((! $console && ! $material->is_available($now)) || ! $authorized($ids)) continue;
            $visible[] = $material;
            if ($material->category !== '') $categories[$material->category] = $material->category;
            foreach ($material->tags as $tag) $tags[$tag] = $tag;
            foreach ($ids as $id) if ($authorized(array($id))) $groups[$id] = $id;
        }
        $group_allowed = $filters['group'] === '' || $authorized(array($filters['group']));
        $filtered = array_values(array_filter($visible, static function (Material $material) use ($filters, $group_map, $now, $group_allowed): bool {
            if (! $group_allowed) return false;
            if ($filters['type'] !== '' && $material->type() !== $filters['type']) return false;
            if ($filters['category'] !== '' && $material->category !== $filters['category']) return false;
            if ($filters['tag'] !== '' && ! in_array($filters['tag'], $material->tags, true)) return false;
            if ($filters['group'] !== '' && ! in_array($filters['group'], $group_map[$material->id] ?? array(), true)) return false;
            if ($filters['status'] !== '' && $material->publication_state($now) !== $filters['status']) return false;
            $haystack = implode(' ', array($material->title, $material->description, $material->original_filename, $material->category, implode(' ', $material->tags)));
            return $filters['q'] === '' || str_contains(strtolower($haystack), strtolower($filters['q']));
        }));
        usort($filtered, static fn (Material $a, Material $b): int => ($b->is_pinned <=> $a->is_pinned) ?: strcmp($b->created_at, $a->created_at) ?: ($b->id <=> $a->id));
        $per_page = max(1, min(100, $per_page));
        $pages = max(1, (int) ceil(count($filtered) / $per_page));
        $requested = isset($query['lmt_page']) && is_scalar($query['lmt_page']) ? (int) $query['lmt_page'] : 1;
        $page = min($pages, max(1, $requested));
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
        return array('items' => array_slice($filtered, ($page - 1) * $per_page, $per_page), 'total' => count($filtered),
            'page' => $page, 'pages' => $pages, 'filters' => $filters, 'categories' => $categories, 'tags' => $tags, 'groups' => array_values($groups));
    }
}
