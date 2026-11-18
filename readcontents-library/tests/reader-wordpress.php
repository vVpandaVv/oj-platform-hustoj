<?php
declare(strict_types=1);

// The repository/handler/renderers are production classes. Only WordPress I/O
// is replaced with in-memory posts and metadata; no site or database is used.
$workspace = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($workspace): void {
    foreach ([
        'CodingPanda\\OjPlatform\\' => $workspace . '/oj-integration-rebuild/oj-platform/src/',
        'ReadContents\\Library\\' => dirname(__DIR__) . '/src/ReadContents/Library/',
        'ContentsEditor\\' => dirname(__DIR__) . '/src/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});
$readerPosts = $readerMeta = [];
$readerSequence = 0;
$readerCanEdit = false;
$readerCanView = $readerLoggedIn = $readerPlatformActive = true;
$readerSlugQueries = [];
#[AllowDynamicProperties]
class WP_Post { public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; } }
class WP_Query { public array $posts; public function __construct(array $args) { $this->posts = get_posts($args); } }
function __($text, ...$unused) { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function esc_html__($text, ...$unused) { return esc_html($text); }
function esc_url($url) { return esc_attr($url); }
function home_url($path = '') { return 'https://reader-layout.test/school' . '/' . ltrim((string) $path, '/'); }
function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value); }
function sanitize_title($text) { return strtolower(trim(strip_tags((string) $text))); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function absint($value) { return abs((int) $value); }
function user_can($user, $capability) { return $capability === 'oj_addon_readcontents_library_view' ? $GLOBALS['readerCanView'] : $GLOBALS['readerCanEdit']; }
function current_user_can($capability) { return user_can(7, $capability); }
function did_action($name) { return $name === 'oj_platform_register_addons' && $GLOBALS['readerPlatformActive'] ? 1 : 0; }
function is_user_logged_in() { return $GLOBALS['readerLoggedIn']; }
function shortcode_atts($defaults, $attributes, $name) { return array_replace($defaults, array_intersect_key($attributes, $defaults)); }
function esc_attr__($text, ...$unused) { return esc_attr($text); }
function sanitize_key($text) { return strtolower((string) $text); }
function remove_query_arg($key) { return home_url('/shortcode'); }
function wp_enqueue_style($handle) {}
function wp_enqueue_script($handle) {}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function get_post($id) { return $GLOBALS['readerPosts'][(int) $id] ?? null; }
function get_the_title($post) { return $post->post_title; }
function get_post_meta($id, $key, ...$unused) { return $GLOBALS['readerMeta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['readerMeta'][(int) $id][$key] = wp_unslash($value); return true; }
function is_wp_error($value) { return false; }
function wp_insert_post($values, ...$unused) {
    $id = ++$GLOBALS['readerSequence'];
    $GLOBALS['readerPosts'][$id] = new WP_Post(['ID' => $id] + wp_unslash($values));
    return $id;
}
function get_posts($args) {
    if (isset($args['name'])) {
        $slug = $args['name'];
        $GLOBALS['readerSlugQueries'][$slug] = 1 + ($GLOBALS['readerSlugQueries'][$slug] ?? 0);
    }
    $posts = array_filter($GLOBALS['readerPosts'], static function ($post) use ($args) {
        foreach (['post_type', 'post_parent'] as $key) if (isset($args[$key]) && $post->$key !== $args[$key]) return false;
        if (isset($args['name']) && $post->post_name !== $args['name']) return false;
        if (isset($args['post_status']) && !in_array($post->post_status, (array) $args['post_status'], true)) return false;
        foreach ($args['meta_query'] ?? [] as $filter) {
            if (!is_array($filter)) continue;
            $equal = get_post_meta($post->ID, $filter['key']) === $filter['value'];
            if (($filter['compare'] ?? '=') === '!=' ? $equal : !$equal) return false;
        }
        return true;
    });
    usort($posts, static fn ($a, $b) => ($a->menu_order <=> $b->menu_order) ?: ($a->ID <=> $b->ID));
    return isset($args['posts_per_page']) && $args['posts_per_page'] > -1 ? array_slice($posts, 0, $args['posts_per_page']) : $posts;
}
function readerCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function wp_update_post($values, ...$unused) {
    $post = get_post($values['ID']);
    foreach ($values as $key => $value) $post->$key = $value;
    return $post->ID;
}
function readerDom(string $html): DOMXPath {
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}
