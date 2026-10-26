<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Database\Round_Repository;

/**
 * Builds the built-in Relay on $wpdb. Reads the secret straight from the
 * options table so it also works from relay.php (SHORTINIT: no get_option).
 */
final class Factory {
    public static function relay(\wpdb $db): Relay {
        return new Relay(
            new Wpdb_Store($db),
            self::secret($db),
            static fn (): array => (new Problem_Repository())->for_game(),
            static function (array $result, string $title): void {
                (new Round_Repository())->save($result, $title);
            }
        );
    }

    public static function secret(\wpdb $db): string {
        $raw = $db->get_var($db->prepare("SELECT option_value FROM {$db->options} WHERE option_name = %s", 'oj_code_arena_settings'));
        $value = is_string($raw) ? @unserialize($raw, array('allowed_classes' => false)) : false;
        return is_array($value) ? (string) ($value['secret'] ?? '') : '';
    }

    /**
     * Handles one raw poll body and returns the JSON response text.
     */
    public static function handle_raw(\wpdb $db, string $body): string {
        $req = json_decode($body, true);
        if (! is_array($req)) {
            return (string) json_encode(array('ok' => false, 'why' => 'bad-request'));
        }
        try {
            $res = self::relay($db)->handle($req);
        } catch (\Throwable $e) {
            $res = array('ok' => false, 'why' => 'server-error');
        }
        return (string) json_encode($res, JSON_UNESCAPED_UNICODE);
    }
}
