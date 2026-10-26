<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

/** Short-lived bearer links, bound to the current file and visibility. */
final class Preview_Link {
    public const TTL = 600;

    /** @param list<string> $group_ids */
    public static function signature(Material $material, array $group_ids, int $expires, string $key, bool $manager_preview = false): string {
        sort($group_ids, SORT_STRING);
        return hash_hmac('sha256', serialize(array(
            'learning-materials-preview-v2', $material->id, $material->storage_filename,
            $material->updated_at, $group_ids, $expires, $material->publication_status,
            $material->publish_from, $material->publish_until, $manager_preview,
        )), $key);
    }

    /** @param list<string> $group_ids */
    public static function valid(Material $material, array $group_ids, int $expires, string $signature, string $key, int $now, bool $manager_preview = false): bool {
        return $expires > $now && $expires <= $now + self::TTL
            && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals(self::signature($material, $group_ids, $expires, $key, $manager_preview), $signature);
    }

    public static function data(Material $material, array $groups, bool $manager_preview): array {
        if ($material->is_google_link()) return array('viewer_url' => Google_Link::parse($material->source_url)['viewer_url']);
        $expires = time() + self::TTL;
        $args = array('action' => 'learning_materials_preview_file', 'material_id' => $material->id, 'expires' => $expires,
            'signature' => self::signature($material, $groups, $expires, wp_salt('auth'), $manager_preview));
        if ($manager_preview) $args['manager_preview'] = '1';
        $url = add_query_arg($args, admin_url('admin-post.php'));
        if ('https' !== wp_parse_url($url, PHP_URL_SCHEME)) {
            throw new \DomainException(__('Google 미리보기에는 외부에서 접근 가능한 HTTPS 주소가 필요합니다.', 'learning-materials'));
        }
        return array('expires_at' => $expires, 'viewer_url' => 'https://docs.google.com/gview?embedded=true&url=' . rawurlencode($url));
    }
}
