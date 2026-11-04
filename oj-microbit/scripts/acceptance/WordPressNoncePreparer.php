<?php
declare(strict_types=1);
namespace OjMicrobit\Acceptance;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use RuntimeException;

final class WordPressNoncePreparer
{
    public static function prepare(array $config): array
    {
        if (AcceptanceSupport::baseUrl(get_option('siteurl', '')) !== AcceptanceSupport::baseUrl($config['baseUrl'] ?? null)) { throw new RuntimeException('site_url_mismatch'); }
        $active = get_option('active_plugins', []);
        if (!is_array($active) || !in_array('oj-microbit/oj-microbit.php', $active, true)) { throw new RuntimeException('invalid_accounts'); }
        if (!is_array($config['accounts'] ?? null)) { throw new RuntimeException('invalid_accounts'); }
        $ids = []; $previous = get_current_user_id();
        try {
            foreach (['teacherA', 'teacherB', 'studentA', 'studentB'] as $label) {
                $account = $config['accounts'][$label] ?? null;
                if (!is_array($account) || !is_string($account['username'] ?? null) || $account['username'] === '' || strlen($account['username']) > 120
                    || preg_match('/[\x00-\x1f\x7f:]/', $account['username']) || !is_string($account['applicationPassword'] ?? null)
                    || $account['applicationPassword'] === '' || strlen($account['applicationPassword']) > 512 || preg_match('/[\x00-\x1f\x7f]/', $account['applicationPassword'])) { throw new RuntimeException('invalid_accounts'); }
                $user = get_user_by('login', $account['username']);
                if (!$user && filter_var($account['username'], FILTER_VALIDATE_EMAIL)) { $user = get_user_by('email', $account['username']); }
                $id = $user ? (int) $user->ID : 0;
                if ($id < 1) { throw new RuntimeException('invalid_accounts'); }
                if (isset($ids[$id])) { throw new RuntimeException('accounts_must_be_distinct'); }
                $ids[$id] = true;
                $teacher = user_can($user, AddonCapabilityKeyV1::wordpress('oj_microbit_teach'));
                $learner = user_can($user, AddonCapabilityKeyV1::wordpress('oj_microbit_learn'));
                if (user_can($user, 'manage_options') || (str_starts_with($label, 'teacher') ? !$teacher : (!$learner || $teacher))) { throw new RuntimeException('account_role_mismatch'); }
                wp_set_current_user($id);
                if (wp_get_session_token() !== '') { throw new RuntimeException('nonce_generation_failed'); }
                $nonce = wp_create_nonce('wp_rest');
                if (!is_string($nonce) || preg_match('/\A[A-Za-z0-9]{10}\z/D', $nonce) !== 1 || wp_verify_nonce($nonce, 'wp_rest') === false) { throw new RuntimeException('nonce_generation_failed'); }
                $config['accounts'][$label]['restNonce'] = $nonce;
            }
        } finally { wp_set_current_user($previous); }
        return $config;
    }
}
