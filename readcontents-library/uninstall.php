<?php
/**
 * Remove reading content only when WordPress uninstalls this plugin.
 *
 * @package ReadContentsLibrary
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/ReadContents/Library/Lifecycle/Uninstaller.php';

try {
    ReadContents\Library\Lifecycle\Uninstaller::run();
} catch (Throwable $error) {
    wp_die(
        esc_html__('읽기자료 데이터 삭제를 완료하지 못했습니다. 일부 항목은 이미 삭제되었을 수 있습니다. 데이터베이스 연결과 삭제 제한을 확인한 뒤 플러그인 삭제를 다시 시도해 주세요.', 'readcontents-library'),
        esc_html__('읽기자료 삭제 중단', 'readcontents-library'),
        ['response' => 500, 'back_link' => true]
    );
}
