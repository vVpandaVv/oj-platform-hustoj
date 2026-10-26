<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use OjCodeLab\Database\Installer;

/** WordPress diagnostics plus an optional bridge to the shared audit logger. */
final class Operations {
    public static function register(): void {
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        add_action('oj_code_lab_audit', array(__CLASS__, 'audit'), 10, 2);
    }
    public static function tests(array $tests): array {
        $tests['direct']['oj_code_lab'] = array('label' => 'OJ Code Lab', 'test' => array(__CLASS__, 'health'));
        return $tests;
    }
    public static function health(): array {
        $ready = Installer::tables_exist() && get_option('oj_code_lab_db_version') === Installer::SCHEMA_VERSION && ! get_option('oj_code_lab_install_error');
        $crypto = function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
        $cron = wp_next_scheduled('oj_code_lab_cache_cleanup');
        $packet_limit = Database_Packet::payload_limit(PHP_INT_MAX);
        $packet_ready = ! is_wp_error($packet_limit) && $packet_limit > 0;
        $notebook_limit = $packet_ready ? min($packet_limit, \OjCodeLab\Database\Notebook_Repository::MAX_PAYLOAD_BYTES) : 0;
        $dataset_configured = Dataset_Limits::configured_max_bytes();
        $dataset_limit = $packet_ready ? min($packet_limit, $dataset_configured) : 0;
        $packet_full = $packet_ready && $notebook_limit === \OjCodeLab\Database\Notebook_Repository::MAX_PAYLOAD_BYTES && $dataset_limit === $dataset_configured;
        $packet_label = $packet_ready ? ('노트북 최대 ' . number_format($notebook_limit) . '바이트 / 데이터 파일 최대 ' . number_format($dataset_limit) . '바이트' . ($packet_full ? '' : ' (DB 패킷 한도에 맞춰 자동 조정)')) : 'DB 저장 한도 확인 필요';
        return array('test' => 'oj_code_lab', 'status' => ! $ready || ! $packet_ready ? 'critical' : ($crypto && $cron && $packet_full ? 'good' : 'recommended'),
            'label' => ! $ready ? 'OJ Code Lab 설치 복구 필요' : (! $packet_ready ? 'OJ Code Lab 저장 한도 확인 필요' : ($packet_full ? 'OJ Code Lab 설치 상태 정상' : 'OJ Code Lab 서버 저장 한도 적용 중')),
            'badge' => array('label' => 'OJ Code Lab', 'color' => 'blue'),
            'description' => '<p>' . esc_html('스키마: ' . ($ready ? '정상' : '확인 필요') . ' / 키 암호화: ' . ($crypto ? '사용 가능' : 'OpenSSL 필요') . ' / 캐시 정리 예약: ' . ($cron ? '등록됨' : '확인 필요') . ' / ZIP 읽기: ' . (class_exists('ZipArchive') ? '사용 가능' : 'PHP zip 확장 필요') . ' / ' . $packet_label) . '</p>',
            'actions' => '');
    }
    public static function audit(string $event, int $actor): void {
        // Record only a fixed event code/time, never keys, source URLs or notebook content.
        if (! in_array($event, array('kaggle_settings_saved', 'kaggle_settings_cleared'), true)) { return; }
        update_option('oj_code_lab_last_settings_event', array('event' => $event, 'at' => gmdate('c')), false);
        if (function_exists('oj_log_audit_event')) {
            oj_log_audit_event('oj_code_lab.' . $event, 'OJ Code Lab Kaggle 설정 변경', array('actor_user_id' => $actor), 'info');
        }
    }
}
