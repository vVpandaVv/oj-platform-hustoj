<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use FocusTracker\Database\Installer;
use FocusTracker\Support\Audit_Log;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Diagnostics;

/** Administrative summaries contain no student code, answers or event IDs. */
final class Operations_Panel {
    public static function render(): string {
        if (! Capabilities::can_manage_settings()) { return ''; }
        $health = get_option('focus_tracker_schema_health', array());
        $cleanup = get_option('focus_tracker_cleanup_status', array());
        $metrics = class_exists(Diagnostics::class) ? Diagnostics::snapshot() : array();
        ob_start();
        ?>
        <section class="ojp-card ftk-operations" aria-label="운영 상태">
            <h2>운영 상태</h2>
            <p data-ftk-storage-health><?php echo esc_html(Installer::ready() ? '저장소 준비 완료' : '저장소 확인 필요 · 기록 수집 중단'); ?></p>
            <p>OJ Platform 2.3.22 이상 · Addon API 1.4 · PHP 8.3 이상이 필요합니다. 현재 화면의 상태는 조회 시점 기준입니다.</p>
            <?php if (isset($metrics['available']) && ! $metrics['available']) : ?><p role="alert">요청 처리 상태를 조회하지 못했습니다. 기록 없음과 구분해 저장소를 확인해 주세요.</p><?php endif; ?>
            <?php if (! empty($health['issues'])) : ?><p role="alert"><?php echo esc_html(implode(', ', $health['issues'])); ?></p><?php endif; ?>
            <dl>
                <dt>저장소 확인</dt><dd><?php echo esc_html(self::time($health['checkedAtMs'] ?? null)); ?></dd>
                <dt>정리 마지막 실행</dt><dd><?php echo esc_html(self::time($cleanup['lastRunAtMs'] ?? null)); ?></dd>
                <dt>정리 마지막 성공</dt><dd><?php echo esc_html(self::time($cleanup['lastSuccessAtMs'] ?? null)); ?></dd>
                <dt>정리 결과</dt><dd><?php echo esc_html(self::outcome((string) ($cleanup['lastOutcome'] ?? ''))); ?></dd>
                <dt>남은 정리 작업</dt><dd><?php echo esc_html('원시 기록 ' . self::tally($cleanup['backlog']['raw'] ?? null) . ' · 문항 ' . self::tally($cleanup['backlog']['checks'] ?? null) . ' · 집계 ' . self::tally($cleanup['backlog']['aggregates'] ?? null) . ' · 세션·연결 ' . self::tally($cleanup['backlog']['metadata'] ?? null)); ?></dd>
                <dt>정리 실패</dt><dd><?php echo (int) ($cleanup['counts']['failed'] ?? 0); ?></dd>
                <dt>다음 이어 처리</dt><dd><?php echo esc_html(self::time($cleanup['retry']['scheduledAtMs'] ?? null)); ?></dd>
            </dl>
            <p>보관 작업은 집계 저장 성공 후 원본을 삭제합니다. 실패하거나 WP-Cron이 지연되면 보관 기간보다 오래 남을 수 있으므로 남은 작업과 실패를 확인해 주세요.</p>
            <div class="ojp-table-wrap" role="region" aria-label="요청 처리 표본 상태" tabindex="0"><table class="ojp-table"><caption>요청 처리 표본 상태</caption><thead><tr><th scope="col">종류</th><th scope="col">최근 성공</th><th scope="col">최근 오류</th><th scope="col">관측 분 수</th></tr></thead><tbody>
            <?php foreach (array('ingest' => '기록 수집', 'gate' => '활동 시간 확인', 'check' => '자동 문항') as $key => $label) : $row = $metrics['operations'][$key] ?? array(); ?>
                <tr><th scope="row"><?php echo esc_html($label); ?></th><td><?php echo esc_html(self::time($row['lastSuccessAtMs'] ?? null)); ?></td><td><?php echo esc_html(self::time($row['lastErrorAtMs'] ?? null)); ?></td><td><?php foreach (($row['counts'] ?? array()) as $reason => $count) { echo esc_html(self::reason((string) $reason) . ': ' . (int) $count . ' '); } ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p>종류·상태별로 분당 한 번만 진단을 저장합니다. 관측 분 수는 전체 요청 횟수가 아니며 최근 시각도 최대 1분 간격의 표본입니다. 검증 가능한 문항 없음은 정상적인 불출제이며 학생의 미집중을 뜻하지 않습니다. 오류·권한·요청 제한은 별도로 표시합니다.</p>
            <details><summary>최근 설정·활동 변경</summary><ul><?php foreach (Audit_Log::recent() as $entry) : ?><li><?php echo esc_html(self::time($entry['atMs']) . ' · 담당자 #' . (int) $entry['actorId'] . ' · ' . self::operation($entry['operation']) . ' #' . (int) $entry['targetId'] . ' · ' . implode(', ', $entry['fields'])); ?></li><?php endforeach; ?></ul></details>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function time($ms): string {
        if (! is_numeric($ms) || (int) $ms < 1) { return '기록 없음'; }
        return (new \DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone(wp_timezone())->format('Y-m-d H:i:s T');
    }
    private static function reason(string $key): string {
        return array('success' => '성공', 'error' => '오류', 'inactive' => '활동 시간 밖', 'disabled' => '기능 꺼짐', 'unsupported' => '지원 자료 없음', 'no_candidate' => '검증 문항 없음', 'access' => '자료 접근 실패', 'store' => '저장 실패', 'cooldown' => '간격·상한 대기', 'limited' => '요청 제한', 'invalid' => '잘못된 요청')[$key] ?? '기타';
    }
    private static function tally($value): string { return is_numeric($value) ? (string) max(0, (int) $value) : '확인 불가'; }
    private static function outcome(string $key): string { return array('running' => '정리 중', 'complete' => '완료', 'continuing' => '이어 처리 대기', 'deferred' => '일부 자료 보류 · 재시도 예정', 'error' => '오류 · 관리자 확인 필요')[$key] ?? '아직 실행 기록 없음'; }
    private static function operation(string $key): string { return array('settings' => '설정 변경', 'activity_created' => '활동 생성', 'activity_updated' => '활동 변경', 'activity_deleted' => '활동 삭제')[$key] ?? '변경'; }
}
