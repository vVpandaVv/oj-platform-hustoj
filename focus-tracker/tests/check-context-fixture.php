<?php
declare(strict_types=1);

/** Test-only platform context and persistent browser-scenario configuration. */

use CodingPanda\OjPlatform\AddonApi\V1 as CheckApi;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

function platform_fixture_check_defaults(): array {
    return array('scenario' => 'unavailable', 'userId' => 1, 'initialDelayMs' => 45000,
        'pollMs' => 60000, 'cooldownSeconds' => 300, 'dailyLimit' => 3);
}

function platform_fixture_check_config(): array {
    global $wpdb;
    $config = $GLOBALS['focus_test_check_config'] ?? array();
    if (isset($wpdb->pdo) && $wpdb->pdo instanceof PDO) {
        $wpdb->pdo->exec('CREATE TABLE IF NOT EXISTS focus_tracker_fixture_check_config (id INTEGER PRIMARY KEY, config_json TEXT NOT NULL)');
        $stored = $wpdb->pdo->query('SELECT config_json FROM focus_tracker_fixture_check_config WHERE id = 1')->fetchColumn();
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) { $config = $decoded; }
        }
    }
    return array_merge(platform_fixture_check_defaults(), is_array($config) ? $config : array());
}

function platform_fixture_apply_check_config(array $config): void {
    $GLOBALS['focus_test_check_config'] = $config;
    $GLOBALS['focus_test_checks_client_config'] = array_intersect_key($config, array('initialDelayMs' => true, 'pollMs' => true));
    $GLOBALS['focus_test_activity_gate_client_config'] = array('pollMs' => (int) ($config['gatePollMs'] ?? 5000));
    $GLOBALS['focus_test_user_id'] = (int) ($config['userId'] ?? 1);
    if (function_exists('update_option')) {
        Settings::update(array('checks_enabled' => true,
            'check_cooldown_seconds' => (int) ($config['cooldownSeconds'] ?? 300),
            'check_daily_limit' => (int) ($config['dailyLimit'] ?? 3)));
    }
}

function platform_fixture_save_check_config(array $input): array {
    global $wpdb;
    $config = ! empty($input['reset']) ? platform_fixture_check_defaults() : platform_fixture_check_config();
    foreach (array('scenario', 'userId', 'initialDelayMs', 'pollMs', 'lifetimeMs', 'cooldownSeconds', 'dailyLimit',
        'problemTitle', 'sampleInput', 'sampleOutput', 'submissionSource', 'submissionId', 'gatePollMs') as $key) {
        if (array_key_exists($key, $input)) { $config[$key] = $input[$key]; }
    }
    if (! in_array($config['scenario'], array('draft', 'submission', 'problem', 'unavailable'), true)) {
        throw new InvalidArgumentException('Unknown check fixture scenario.');
    }
    $config['userId'] = max(0, (int) $config['userId']);
    foreach (array('initialDelayMs', 'pollMs', 'lifetimeMs', 'cooldownSeconds', 'dailyLimit') as $key) {
        if (isset($config[$key])) { $config[$key] = max(1, (int) $config[$key]); }
    }
    if (! empty($input['reset'])) {
        foreach (array('checks', 'check_states') as $table) { $wpdb->query('DELETE FROM wp_focus_tracker_' . $table); }
    }
    platform_fixture_check_config(); // Create the isolated test config table if needed.
    $statement = $wpdb->pdo->prepare('INSERT INTO focus_tracker_fixture_check_config (id, config_json) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET config_json=excluded.config_json');
    $statement->execute(array(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    if (! empty($input['expirePending'])) {
        $wpdb->query('UPDATE wp_focus_tracker_checks SET expires_at_ms = ' . (Clock::now_ms() - 1) . " WHERE status = 'pending'");
    }
    platform_fixture_apply_check_config($config);
    return $config;
}

function platform_fixture_reset_check_config(): void {
    global $wpdb;
    platform_fixture_check_config();
    $wpdb->pdo->exec('DELETE FROM focus_tracker_fixture_check_config');
    platform_fixture_apply_check_config(platform_fixture_check_defaults());
}

/** Shortened lifetimes exercise expiry without changing the production service. */
function platform_fixture_check_response($response) {
    global $wpdb;
    $config = platform_fixture_check_config();
    if ($response instanceof WP_REST_Response && ($response->data['status'] ?? null) === 'ready'
        && isset($config['lifetimeMs'], $response->data['check']['id'])) {
        $expires = Clock::now_ms() + max(1, min(600000, (int) $config['lifetimeMs']));
        $wpdb->update('wp_focus_tracker_checks', array('expires_at_ms' => $expires),
            array('id' => (int) $response->data['check']['id'], 'user_id' => get_current_user_id()));
        $response->data['check']['expiresAtMs'] = $expires;
    }
    return $response;
}

final class Platform_Fixture_Learning_Check_Gateway implements CheckApi\AddonLearningCheckGatewayV1 {
    public function context(CheckApi\AddonLearningCheckContextQueryV1 $query): CheckApi\AddonLearningCheckContextResultV1 {
        $config = platform_fixture_check_config();
        if ($query->actorUserId() !== get_current_user_id() || $query->problemId() !== 123) {
            return $this->failed('addon_access_denied');
        }
        if ($config['scenario'] === 'unavailable') { return $this->failed('addon_runtime_unavailable'); }
        if ($query->contextType() !== 'general' && $query->contextId() !== 1) { return $this->failed('addon_access_denied'); }
        $sample = $config['scenario'] === 'problem';
        $problem = array('id' => 123, 'title' => (string) ($config['problemTitle'] ?? '두 수의 합'),
            'description' => '입력한 두 정수의 합을 구하세요.', 'input' => '두 정수', 'output' => '두 정수의 합',
            'sample_input' => (string) ($config['sampleInput'] ?? ($sample ? '3 4' : '')),
            'sample_output' => (string) ($config['sampleOutput'] ?? ($sample ? '7' : '')));
        $problem['content_hash'] = hash('sha256', json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $submission = null;
        if ($config['scenario'] === 'submission') {
            $id = (int) ($config['submissionId'] ?? 99);
            if ($query->submissionId() !== null && $query->submissionId() !== $id) { return $this->failed('addon_access_denied'); }
            $submission = array('id' => $id, 'problem_id' => 123,
                'source' => (string) ($config['submissionSource'] ?? "value = 8\nif value <= 10:\n    print(value)\n"),
                'language_id' => 6, 'language' => 'python', 'result_code' => 4,
                'submitted_at' => '2026-10-23 10:00:00', 'processing' => false);
        } elseif ($query->submissionId() !== null) { return $this->failed('addon_access_denied'); }
        return new CheckApi\AddonLearningCheckContextResultV1(true, array('problem' => $problem, 'submission' => $submission), null);
    }

    private function failed(string $code): CheckApi\AddonLearningCheckContextResultV1 {
        return new CheckApi\AddonLearningCheckContextResultV1(false, array(), new CheckApi\AddonApiProblemV1(
            $code, 'Fixture learning check context unavailable.', 'fixture-learning-check', $code === 'addon_runtime_unavailable'));
    }
}
