<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityPostTypeRegistrar;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityRepository;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$GLOBALS['native_entry_filters'] = [];
$GLOBALS['native_entry_posts'] = [
    71 => (object) ['ID' => 71, 'post_type' => 'oj_board_post', 'comment_status' => 'open'],
    72 => (object) ['ID' => 72, 'post_type' => 'oj_notice', 'comment_status' => 'open'],
    73 => (object) ['ID' => 73, 'post_type' => 'post', 'comment_status' => 'open'],
    74 => (object) ['ID' => 74, 'post_type' => 'page', 'comment_status' => 'closed'],
];
$GLOBALS['native_entry_writes'] = [];

function register_post_type(string $type, array $arguments): void {}
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArguments = 1): bool
{
    $GLOBALS['native_entry_filters'][$hook][] = [$callback, $acceptedArguments];
    return true;
}
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
{
    foreach ($GLOBALS['native_entry_filters'][$hook] ?? [] as [$callback, $acceptedArguments]) {
        $value = $callback(...array_slice([$value, ...$arguments], 0, $acceptedArguments));
    }
    return $value;
}
function get_post(int $id): ?object { return $GLOBALS['native_entry_posts'][$id] ?? null; }
function get_post_type(int $id): string|false { return get_post($id)?->post_type ?? false; }
function comments_open(int $id): bool { return (bool) apply_filters('comments_open', get_post($id)?->comment_status === 'open', $id); }
function wp_insert_comment(array $data): int { $GLOBALS['native_entry_writes'][] = $data; return count($GLOBALS['native_entry_writes']); }
function update_comment_meta(int $id, string $key, mixed $value): bool { return true; }
function current_time(string $type, bool $gmt = false): string { return '2026-10-23 12:00:00'; }

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};

(new WordPressCommunityPostTypeRegistrar())->register();
$assert(count($GLOBALS['native_entry_filters']['comments_open'] ?? []) === 1, 'CPT registration must protect the native comment entry point.');
foreach ([71, 72] as $postId) {
    $assert(! comments_open($postId), 'Native comments must be closed for OJ posts even when their stored comment status is open.');
    $assert(get_post($postId)?->comment_status === 'open', 'Closing the native entry must preserve the OJ comment setting.');
}
$assert(comments_open(73), 'Ordinary WordPress post comments must remain available.');
$assert(! comments_open(74), 'An existing closed WordPress page must remain closed.');
$assert(! comments_open(999), 'An unknown post must remain closed.');

// Authorized OJ comments use wp_insert_comment, which never consults comments_open.
$repository = new WordPressCommunityRepository();
$commentId = $repository->createComment(71, 10, '<p>authorized OJ comment</p>', 'synthetic-hash');
$assert($commentId === 1 && $GLOBALS['native_entry_writes'][0]['comment_post_ID'] === 71, 'The OJ repository must still store authorized comments.');

// When a local WordPress source tree is supplied, execute the exact native entry
// function with synthetic dependencies. No WordPress bootstrap, database or HTTP.
$wordpressRoot = getenv('OJ_TEST_WORDPRESS_ROOT');
if (is_string($wordpressRoot) && $wordpressRoot !== '') {
    final class WP_Error {
        public function __construct(public string $code, mixed ...$arguments) {}
    }
    function __(string $value): string { return $value; }
    function get_post_status(object $post): string { return 'publish'; }
    function get_post_status_object(string $status): object { return (object) ['public' => true, 'private' => false]; }
    function current_user_can(string $capability, mixed ...$arguments): bool { return false; }
    function post_password_required(int $id): bool { return false; }
    function do_action(string $hook, mixed ...$arguments): void {}
    function wp_get_current_user(): object { return new class {
        public int $ID = 20;
        public string $display_name = 'outside learner';
        public string $user_email = 'learner@example.invalid';
        public string $user_url = '';
        public function exists(): bool { return true; }
    }; }
    function get_option(string $key): bool { return false; }
    function wp_check_comment_data_max_lengths(array $data): bool { return true; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function wp_slash(mixed $value): mixed { return $value; }
    function wp_new_comment(array $data, bool $returnError): int { return wp_insert_comment($data); }
    function get_comment(int $id): object { return (object) ['comment_ID' => $id]; }

    $source = file_get_contents(rtrim($wordpressRoot, '/\\') . '/wp-includes/comment.php');
    $start = strpos($source, 'function wp_handle_comment_submission( ');
    $end = $start === false ? false : strpos($source, '/**', strpos($source, 'return get_comment( $comment_id );', $start));
    $assert($start !== false && $end !== false, 'The supplied WordPress source must contain the native comment entry.');
    eval(substr($source, $start, $end - $start));
    foreach ([71, 72] as $postId) {
        $before = count($GLOBALS['native_entry_writes']);
        $result = wp_handle_comment_submission(['comment_post_ID' => $postId, 'comment' => 'synthetic outside comment']);
        $assert($result instanceof WP_Error && $result->code === 'comment_closed', 'Actual core native submissions must reject OJ posts.');
        $assert(count($GLOBALS['native_entry_writes']) === $before, 'Rejected native requests must never reach comment storage.');
    }
    $result = wp_handle_comment_submission(['comment_post_ID' => 73, 'comment' => 'ordinary WP comment']);
    $assert(! $result instanceof WP_Error, 'Actual core must still accept ordinary WordPress comments.');
}

fwrite(STDOUT, sprintf("Community native entry checks passed: %d assertions\n", $assertions));
