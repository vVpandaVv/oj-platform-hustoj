<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationStateService;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationState;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use JsonException;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class MigrationPlanningPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly MigrationStateService $states,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->states->snapshot();
        $state = $this->states->current();
        $baseline = $state->baseline();
        $evidence = $state->recoveryEvidence();
        $blockers = $this->jsonList($baseline['blockers_json'] ?? null);
        $warnings = $this->jsonList($baseline['warnings_json'] ?? null);
        $planReady = $state->status() === 'planned'
            && $state->lastResultCode() === 'migration.plan_created'
            && $state->planHash() !== null;

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '승인형 전환 도구</p><h1>데이터 이전</h1>'
            . '<p>사전 검사, 복구 증거, 계획 해시, Jobs 검증과 7일 관찰을 순서대로 진행합니다.</p>'
            . '</div></header>'
            . '<section class="ojp-section"><div class="ojp-description-grid">'
            . $this->summary('상태', $state->status())
            . $this->summary('현재 파동', $state->wave()->label())
            . $this->summary(
                '현재 단계',
                $state->stage()->value . ' · ' . $state->stage()->label()
            )
            . $this->summary('관찰', $state->observationDayCount() . ' / 7일')
            . $this->summary('설정 revision', (string) $snapshot->revision())
            . $this->summary(
                '마지막 결과',
                $state->lastResultCode() ?? '아직 없음'
            )
            . '</div></section>'
            . $this->scanSection($snapshot->revision(), $baseline, $blockers, $warnings)
            . $this->preparationSection($snapshot->revision(), $evidence, $state->ownerAssignments())
            . $this->planSection($snapshot->revision(), $state, $planReady)
            . $this->cleanupSection($snapshot->revision(), $state);

        return $this->pages->respond(
            '데이터 이전',
            $content,
            'operations.migration',
            $request
        );
    }

    /**
     * @param array<string, scalar|null> $baseline
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private function scanSection(
        int $revision,
        array $baseline,
        array $blockers,
        array $warnings
    ): string {
        $scannedAt = isset($baseline['scanned_at'])
            ? $this->dates->format((string) $baseline['scanned_at'])
            : '아직 실행하지 않음';
        $html = '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>1. 읽기 전용 기준 검사</h2><p>원본·목표 구조와 Jobs 준비 상태만 확인하며 데이터를 변경하지 않습니다.</p></div>'
            . $this->postButton('oj_migration_scan', $revision, '사전 검사 실행')
            . '</div><p><strong>최근 검사:</strong> ' . $this->escape($scannedAt) . '</p>';
        if ($blockers !== []) {
            $html .= '<div class="ojp-notice ojp-notice--danger"><strong>차단 항목</strong><ul>'
                . $this->items($blockers) . '</ul></div>';
        } elseif ($scannedAt !== '아직 실행하지 않음') {
            $html .= '<div class="ojp-notice ojp-notice--success"><strong>계획 가능</strong>'
                . '<p>현재 기준 검사에서 차단 항목을 찾지 못했습니다.</p></div>';
        }
        if ($warnings !== []) {
            $html .= '<details><summary>주의 항목 ' . count($warnings) . '개</summary><ul>'
                . $this->items($warnings) . '</ul></details>';
        }

        return $html . '</section>';
    }

    /**
     * @param array<string, scalar|null> $evidence
     * @param array<string, int> $owners
     */
    private function preparationSection(
        int $revision,
        array $evidence,
        array $owners
    ): string {
        $verified = (string) ($evidence['verified_at'] ?? '');
        if ($verified !== '') {
            $verified = $this->dates->format($verified, 'Y-m-d\\TH:i');
        }
        try {
            $ownerJson = json_encode(
                $owners,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            $ownerJson = '{}';
        }

        return '<section class="ojp-section"><h2>2. 복구 증거와 담당자</h2>'
            . '<p>실제 백업·복구는 분리된 운영 도구에서 수행하고 이 화면에는 참조와 SHA-256만 기록합니다.</p>'
            . $this->formStart('oj_save_migration_settings', $revision)
            . '<div class="ojp-form-grid">'
            . $this->field('backup_ref', '백업 증거 참조', (string) ($evidence['backup_ref'] ?? ''))
            . $this->field('backup_hash', '백업 SHA-256', (string) ($evidence['backup_hash'] ?? ''))
            . $this->field('restore_ref', '복구 증거 참조', (string) ($evidence['restore_ref'] ?? ''))
            . $this->field('restore_hash', '복구 SHA-256', (string) ($evidence['restore_hash'] ?? ''))
            . $this->field(
                'restore_duration_seconds',
                '복구 소요 시간(초)',
                (string) ($evidence['restore_duration_seconds'] ?? ''),
                'number'
            )
            . $this->field('verified_at', '복구 검증 시각', $verified, 'datetime-local')
            . '</div><label class="ojp-field"><span class="ojp-label">미배정 담당자 JSON</span>'
            . '<textarea class="ojp-textarea" name="owner_assignments" rows="7">'
            . $this->escape($ownerJson) . '</textarea></label>'
            . '<p class="ojp-form-note">키 형식: problem:1000, class:3, contest:2, group:4. 값은 숫자 WordPress user_id입니다.</p>'
            . '<button class="ojp-button ojp-button--primary" type="submit">준비 정보 저장</button>'
            . '</form></section>';
    }

    private function planSection(int $revision, MigrationState $state, bool $planReady): string
    {
        $planHash = $state->planHash();
        $html = '<section class="ojp-section"><h2>3. 계획과 Jobs 실행</h2>'
            . '<p>다음 단계의 테이블·변환기·증거·담당자 값을 고정해 계획 해시를 만듭니다.</p>'
            . $this->formStart('oj_migration_dry_run', $revision)
            . '<button class="ojp-button" type="submit">다음 단계 계획 만들기</button></form>';
        if ($planHash !== null) {
            $html .= '<dl class="ojp-description-grid">'
                . $this->summary('현재 계획 해시', $planHash)
                . $this->summary('계획 단계', $state->stage()->label())
                . '</dl>';
        }
        if ($planReady && $planHash !== null) {
            $action = match ($state->stage()) {
                MigrationStage::Validation => 'oj_migration_validate',
                MigrationStage::Cleanup => 'oj_migration_cleanup_report',
                default => 'oj_migration_run',
            };
            $html .= '<div class="ojp-notice ojp-notice--warning"><strong>명시 승인 필요</strong>'
                . '<p>계획 해시를 다시 확인하고 확인란에 <code>이전 실행</code>을 입력해야 Jobs가 등록됩니다.</p></div>'
                . $this->formStart($action, $revision)
                . '<input type="hidden" name="plan_hash" value="' . $this->escape($planHash) . '">'
                . $this->field('confirmation', '확인 문구')
                . '<button class="ojp-button ojp-button--danger" type="submit">승인하고 Jobs 등록</button>'
                . '</form>';
        }
        if ($state->lastJobId() !== null) {
            $html .= '<p><strong>최근 Jobs:</strong> #' . $state->lastJobId() . '</p>';
        }

        return $html . '</section>';
    }

    private function cleanupSection(int $revision, MigrationState $state): string
    {
        $plugins = $state->legacyPlugins();
        $html = '<section class="ojp-section"><h2>4. 관찰과 정리</h2>'
            . '<p>각 데이터 파동은 7일 연속 관찰을 통과해야 다음 파동으로 진행합니다. 정리 보고서는 삭제 후보만 기록합니다.</p>'
            . '<p><strong>기존 플러그인 후보:</strong> '
            . ($plugins === [] ? '없음' : $this->escape(implode(', ', $plugins)))
            . '</p>';
        if ($state->stage() === MigrationStage::Cleanup
            && in_array($state->lastResultCode(), [
                'migration.cleanup_report_ready',
                'migration.legacy_plugin_deactivation_failed',
                'migration.legacy_plugins_still_active',
            ], true)) {
            $html .= '<details class="ojp-danger-details"><summary>기존 플러그인 비활성화</summary>'
                . '<div><p>테이블과 파일은 삭제하지 않습니다. 승인된 플러그인 파일만 비활성화합니다.</p>'
                . $this->formStart('oj_migration_deactivate_legacy_plugins', $revision)
                . $this->field('confirmation', '기존 플러그인 정리 입력')
                . '<button class="ojp-button ojp-button--danger" type="submit">비활성화 실행</button>'
                . '</form></div></details>';
        }

        return $html . '</section>';
    }

    private function postButton(string $action, int $revision, string $label): string
    {
        return $this->formStart($action, $revision)
            . '<button class="ojp-button" type="submit">' . $this->escape($label)
            . '</button></form>';
    }

    private function formStart(string $action, int $revision): string
    {
        return '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="expected_revision" value="' . $revision . '">';
    }

    private function field(
        string $name,
        string $label,
        string $value = '',
        string $type = 'text'
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="'
            . $this->escape($type) . '" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '" required></label>';
    }

    private function summary(string $label, string $value): string
    {
        return '<div><dt>' . $this->escape($label) . '</dt><dd>'
            . $this->escape($value) . '</dd></div>';
    }

    /** @param list<string> $values */
    private function items(array $values): string
    {
        return implode('', array_map(
            fn (string $value): string => '<li>' . $this->escape($value) . '</li>',
            $values
        ));
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded)
            ? array_values(array_filter(array_map(
                static fn (mixed $item): string => is_string($item) ? $item : '',
                $decoded
            )))
            : [];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
