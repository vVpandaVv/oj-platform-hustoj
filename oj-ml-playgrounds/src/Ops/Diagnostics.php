<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Core\Composition_Root;
use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

final class Diagnostics
{
    public function __construct(private readonly Composition_Root $composition) {}

    public function collect(): array
    {
        global $wpdb;
        $checks = [];
        $settings = Options::all();
        $check = static function (string $id, string $label, callable $test) use (&$checks): void {
            try {
                $value = $test();
                $checks[] = ['id' => $id, 'label' => $label, 'status' => $value === true ? 'ok' : 'warning',
                    'message' => $value === true ? '정상' : (string) $value];
            } catch (Throwable) {
                $checks[] = ['id' => $id, 'label' => $label, 'status' => 'error', 'message' => '확인 실패. 설치 안내와 오류 로그를 확인하세요.'];
            }
        };
        $check('maintenance', '실습 점검 모드', static fn (): bool|string => $settings['maintenanceMode'] ? '새 시도·제출·평가·자동 검사가 중지되어 있습니다.' : true);
        $check('https', 'HTTPS / 브라우저 암호화', static fn (): bool|string =>
            strtolower((string) parse_url(home_url('/'), PHP_URL_SCHEME)) === 'https' ? true : '운영 사이트에는 HTTPS가 필요합니다.');
        $check('single_site', '단일 사이트 / 데이터 소유권', static function (): bool { SchemaUpgrade::assertOwnership(); return true; });
        $check('schema', '데이터베이스 스키마 / InnoDB', static function () use ($wpdb): bool|string {
            if ((string) get_option(Installer::DB_VERSION_OPTION, '') !== Installer::DB_VERSION) { return 'DB 업데이트 필요'; }
            SchemaUpgrade::verify($wpdb); return true;
        });
        $check('template_library', '양식 보관함 트랜잭션', static function () use ($wpdb): bool|string {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options));
            return strtoupper((string) $engine) === 'INNODB' ? true : 'WordPress options 테이블이 InnoDB여야 감사 기록과 양식을 함께 저장할 수 있습니다.';
        });
        $check('teaching_datasets', '지정 CSV 보관 한도 (제출물과 별도)', static function (): bool|string {
            $records = get_option(\OJML\Playgrounds\Application\TeachingDatasetService::OPTION, []);
            if (!is_array($records)) { throw new \RuntimeException('datasets'); }
            $bytes = array_sum(array_map(static fn (array $record): int => (int) ($record['bytes'] ?? 0), $records));
            return count($records) < 200 && $bytes < 209715200 ? true : '수업 CSV 보관 한도에 도달했습니다. 숨긴 자료도 포함됩니다. 기관 보존 정책에 따른 정리가 필요합니다.';
        });
        $zip = class_exists('ZipArchive');
        $check('zip', 'PHP ZIP 확장', static fn (): bool|string => $zip ? true : 'PHP-FPM의 ZIP 확장이 필요합니다.');
        $root = realpath(PrivateArtifactStore::configuredRoot());
        $storage = is_string($root) && is_writable($root);
        $check('storage', '비공개 저장소', static function () use ($storage): bool {
            if (!$storage) { throw new \RuntimeException('storage'); }
            new PrivateArtifactStore(); return true;
        });
        $free = is_string($root) ? @disk_free_space($root) : false;
        $check('disk', '저장소 여유 공간', static fn (): bool|string => $free !== false && $free >= $settings['minimumFreeBytes'] + $settings['maxArtifactBytes'] * (4 + (int) $settings['inspectionSlots']) ? true : '여유 공간이 제출 안전 기준보다 작습니다.');
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));
        $check('upload', 'PHP 업로드 제한', static fn (): bool|string => $upload >= $settings['maxArtifactBytes'] && ($post === 0 || $post >= $settings['maxArtifactBytes'] + 1048576) ? true : 'upload_max_filesize / post_max_size를 ML 파일 한도보다 크게 설정하세요.');
        $state = $this->composition->diagnostics();
        $check('runtime', 'OJ Platform 연결', static fn (): bool|string => ($state['ready'] ?? false) && ($state['runtime']['state'] ?? '') === 'ready' && ($state['runtime']['code'] ?? null) === '' ? true : '연결 대기: ' . ($state['code'] ?: ($state['runtime']['code'] ?? 'unavailable')));
        $metrics = [];
        try {
            $metrics = $wpdb->get_row("SELECT
                (SELECT COALESCE(SUM(bytes),0) FROM oj_ml_artifacts WHERE deleted_at IS NULL) AS stored_bytes,
                SUM(status IN ('quarantined','system_pending')) AS pending,
                SUM(status IN ('quarantined','system_pending') AND inspection_retries >= 5) AS needs_operator,
                MIN(CASE WHEN status IN ('quarantined','system_pending') THEN received_at END) AS oldest_pending
                FROM oj_ml_attempts", ARRAY_A);
            SchemaUpgrade::assertDatabase($wpdb);
            $metrics = is_array($metrics) ? $metrics : [];
            $check('quota', 'ML 저장 한도', static fn (): bool|string => (int) ($metrics['stored_bytes'] ?? 0) < $settings['siteQuotaBytes'] ? true : '저장 한도에 도달했습니다.');
            $check('recovery', '검사 복구', static fn (): bool|string => (int) ($metrics['needs_operator'] ?? 0) === 0 ? true : '자동 재시도가 끝난 제출물이 있습니다. 저장소 확인 후 재검사하세요.');
        } catch (Throwable) {
            $checks[] = ['id' => 'metrics', 'label' => '제출 통계', 'status' => 'error', 'message' => '통계를 읽을 수 없습니다. DB 업데이트를 확인하세요.'];
        }
        $ok = count(array_filter($checks, static fn (array $row): bool => $row['status'] !== 'ok')) === 0;
        return ['ok' => $ok, 'checks' => $checks, 'metrics' => $metrics,
            'zipExtension' => $zip, 'storageConfigured' => $storage, 'runtime' => $state['runtime'],
            'freeBytes' => $free === false ? null : (int) $free, 'uploadBytes' => $upload, 'postBytes' => $post,
            'version' => OJ_ML_PLAYGROUNDS_VERSION, 'schemaVersion' => (string) get_option(Installer::DB_VERSION_OPTION, ''),
            'platformVersion' => defined('OJ_PLATFORM_VERSION') ? OJ_PLATFORM_VERSION : null,
            'playgroundVersion' => '0.12.1'];
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (float) $value;
        return (int) ($number * match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
    }
}
