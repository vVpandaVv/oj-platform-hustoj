<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require $path;
});
use ReadContents\Library\Editor\Storage\ContentRepository;
use ReadContents\Library\Editor\Rest\ContentRoutes;
use ReadContents\Library\Editor\Content\ContentAccessPolicy;
use ReadContents\Library\Editor\Content\ContentStatus;

$posts = $meta = $audit = $routes = [];
$sequence = 0; $deleteVeto = null; $canPublish = true; $canEdit = true;
#[AllowDynamicProperties]
class WP_Post { public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; } }
class WP_Query { public array $posts; public function __construct(array $args) { $this->posts = get_posts($args); } }
class WP_REST_Request extends ArrayObject { public function get_param($key) { return $this[$key] ?? null; } }
class WP_REST_Response { public function __construct(public array $data, public int $status) {} }
class WP_Error { public function __construct(public string $code, public string $message, public array $data) {} }
class WP_REST_Server { const READABLE = 'GET', CREATABLE = 'POST', EDITABLE = 'PUT', DELETABLE = 'DELETE'; }
class CapabilityDouble { public static function wordpress($key) { return $key; } }
class_alias(CapabilityDouble::class, 'CodingPanda\\OjPlatform\\AddonApi\\V1\\AddonCapabilityKeyV1');
function current_user_can($cap) { return str_ends_with($cap, '_publish') ? $GLOBALS['canPublish'] : $GLOBALS['canEdit']; }
function register_rest_route($namespace, $route, $config) { $GLOBALS['routes'][$route] = $config; }
function oj_log_audit_event(...$args) { $GLOBALS['audit'][] = $args; }
function __($text, ...$rest) { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_title($value) { return strtolower(trim($value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = wp_unslash($value); return true; }
function get_post_meta($id, $key, ...$rest) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_the_title($post) { return $post->post_title; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_insert_post($values, ...$rest) { $id = ++$GLOBALS['sequence']; $GLOBALS['posts'][$id] = new WP_Post(['ID' => $id] + wp_unslash($values)); return $id; }
function wp_update_post($values, ...$rest) { foreach (wp_unslash($values) as $key => $value) $GLOBALS['posts'][$values['ID']]->$key = $value; return $values['ID']; }
function wp_delete_post($id, $force = false) {
    if ((string) $id === $GLOBALS['deleteVeto']) return false;
    $post = get_post($id); unset($GLOBALS['posts'][$id], $GLOBALS['meta'][$id]); return $post;
}
function get_posts($args) {
    $result = array_filter($GLOBALS['posts'], static function ($post) use ($args) {
        foreach (['post_type', 'post_parent'] as $key) if (isset($args[$key]) && $post->$key !== $args[$key]) return false;
        if (isset($args['post_status']) && !in_array($post->post_status, (array) $args['post_status'], true)) return false;
        foreach ($args['meta_query'] ?? [] as $filter) {
            if (!is_array($filter)) continue;
            $equal = get_post_meta($post->ID, $filter['key']) === $filter['value'];
            if (($filter['compare'] ?? '=') === '!=' ? $equal : !$equal) return false;
        }
        return true;
    });
    usort($result, static fn ($a, $b) => $a->menu_order <=> $b->menu_order);
    return $result;
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function response($response): array { check($response instanceof WP_REST_Response, 'REST error: ' . ($response->message ?? '')); return $response->data; }

$repo = new ContentRepository();
$api = new ContentRoutes($repo);
$policy = new ContentAccessPolicy(fn ($id) => $repo->getNode($id));
$parent = null; $nodes = [];
foreach (['topic', 'chapter', 'section', 'lesson'] as $type) {
    $node = $repo->createNode(['type' => $type, 'title' => $type, 'parentId' => $parent, 'status' => 'published']);
    $nodes[$type] = $node; $parent = $node->id;
}
$lesson = $nodes['lesson'];
$doc = $repo->saveLessonDocument($lesson->id, ['plateValue' => [['type' => 'p', 'children' => [['text' => '보존할 본문']]]], 'revision' => 1])->toArray();
$attachment = wp_insert_post(['post_type' => 'attachment', 'post_parent' => 0, 'post_status' => 'publish', 'menu_order' => 0]);
update_post_meta($attachment, 'unrelated', 'keep');
$api->register();
$canEdit = false;
foreach (['/nodes/(?P<id>[\d]+)/delete', '/nodes/(?P<id>[\d]+)/restore'] as $route) check(!$routes[$route]['permission_callback'](), 'Edit permission required');
$canEdit = true; $canPublish = false;
foreach (['archiveNode', 'deleteNode'] as $action) {
    $result = $api->$action(new WP_REST_Request(['id' => $lesson->id]));
    check($result instanceof WP_Error && $result->data['status'] === 403, 'Published content requires publish permission');
}
$canPublish = true;
foreach ($nodes as $type => $node) {
    response($api->archiveNode(new WP_REST_Request(['id' => $node->id])));
    check(!$policy->isPubliclyReadable($repo->getNode($lesson->id)), 'Archived ancestor hides descendant');
    $listed = response($api->listNodes(new WP_REST_Request(['status' => 'archived'])));
    check(count($listed) === 1 && $listed[0]['type'] === $type, 'All archive types listed');
    response($api->restoreNode(new WP_REST_Request(['id' => $node->id])));
    check($repo->getNode($node->id)->status === ContentStatus::DRAFT, 'Restore as draft');
    $repo->updateNode($node->id, ['status' => 'published']);
    check($repo->getLessonDocument($lesson->id)->toArray() === $doc, 'Archive/restore preserves document and revision');
}
$result = $api->restoreNode(new WP_REST_Request(['id' => $lesson->id]));
check($result instanceof WP_Error && $result->data['status'] === 400, 'Restore cannot unpublish active content');
$repo->archiveNode($lesson->id); $repo->archiveNode($nodes['chapter']->id);
$result = $api->restoreNode(new WP_REST_Request(['id' => $lesson->id]));
check($result instanceof WP_Error && str_contains($result->message, '상위'), 'Archived ancestor must be restored first');
$repo->restoreNode($nodes['chapter']->id); $repo->restoreNode($lesson->id);
$deleteVeto = $lesson->id;
$result = $api->deleteNode(new WP_REST_Request(['id' => $lesson->id]));
check($result instanceof WP_Error, 'Veto reported as failure');
check($repo->getLessonDocument($lesson->id)->toArray() === $doc, 'Failed deletion must retain body metadata');
$deleteVeto = null;
$repo->archiveNode($lesson->id); $repo->archiveNode($nodes['chapter']->id);
$result = response($api->deleteNode(new WP_REST_Request(['id' => $nodes['topic']->id])));
check($result['deleted'] === ['topics' => 1, 'chapters' => 1, 'sections' => 1, 'lessons' => 1], 'Recursive delete includes archived children');
check(count($repo->listNodes(['includeArchived' => true])) === 0, 'No residual content');
check(!isset($meta[(int) $lesson->id]), 'Deleted lesson metadata removed');
check(get_post($attachment) !== null && get_post_meta($attachment, 'unrelated') === 'keep', 'Shared attachments preserved');
check(count($audit) === 9, 'Archive/restore/delete success audits');
echo "PASS: all archive types; draft restore; permissions; ancestor visibility; failure preserves body; recursive delete; media retained; audit.\n";
