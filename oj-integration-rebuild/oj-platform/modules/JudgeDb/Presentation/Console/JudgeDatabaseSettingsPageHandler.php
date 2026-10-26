<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;

final class JudgeDatabaseSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly JudgeConnectionCommandService $settings,
        private readonly CurrentAccountProvider $accounts,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $probeSnapshot = $this->settings->probeSnapshot();
        $current = $this->settings->current();
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $proof = $actorUserId > 0 ? $this->settings->proofFor($actorUserId) : null;
        $host = $current?->host() ?? '127.0.0.1';
        $readUser = $current?->readCredentials()->username() ?? '';
        $writeUser = $current?->writeCredentials()->username() ?? $readUser;
        $pendingExtensions = $proof === null
            ? []
            : array_values(array_filter(
                (array) ($proof->safeResult()['pending_extension_columns'] ?? []),
                static fn (mixed $column): bool => is_string($column) && $column !== ''
            ));
        $apply = $proof === null
            ? '<div class="ojp-notice ojp-notice--info"><strong>후보 검사 필요</strong>'
                . '<p>핵심 채점 테이블 8개, 제출 호환 테이블 1개와 '
                . '읽기·쓰기 연결을 확인한 뒤 적용할 수 있습니다.</p></div>'
            : '<div class="ojp-notice ojp-notice--success"><strong>후보 검사 통과</strong>'
                . '<p>' . ($pendingExtensions === []
                    ? '확장 필드가 모두 준비되어 있습니다.'
                    : '적용할 때 없는 확장 필드를 생성합니다: '
                        . $this->escape(implode(', ', $pendingExtensions)))
                . ' 검사 결과는 15분 동안 유효합니다.</p></div>'
                . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl())
                . '">' . $this->security->hiddenFields('oj_save_judge_db_settings')
                . '<input type="hidden" name="operation" value="apply">'
                . '<input type="hidden" name="expected_revision" value="'
                . $snapshot->revision() . '">'
                . '<button class="ojp-button ojp-button--primary" type="submit">'
                . '검사한 후보 적용</button></form>';
        $eyebrow = $current === null ? '초기 구성 · 2단계' : 'HUSTOJ 채점 데이터 유지';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . $eyebrow . '</p><h1>저지 데이터베이스</h1>'
            . '<p>채점 테이블은 유지하고 problem 확장 필드가 없을 때만 검사한 연결의 적용 단계에서 생성합니다.</p>'
            . '</div></header><div class="ojp-two-column"><section class="ojp-section">'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_judge_db_settings')
            . '<input type="hidden" name="operation" value="test">'
            . '<input type="hidden" name="expected_probe_revision" value="'
            . $probeSnapshot->revision() . '"><div class="ojp-form-grid">'
            . $this->field('host', '호스트', $host, 'text', true)
            . $this->field('port', '포트', (string) ($current?->port() ?? 3306), 'number', true)
            . $this->field('database', '데이터베이스', 'jol', 'text', true, true)
            . $this->field('read_username', '읽기 계정', $readUser, 'text', true)
            . $this->field('read_password', '읽기 비밀번호', '', 'password')
            . $this->field('write_username', '쓰기 계정', $writeUser, 'text', true)
            . $this->field('write_password', '쓰기 비밀번호', '', 'password')
            . $this->field('tls_ca_path', 'TLS CA 경로', $current?->tlsCaPath() ?? '')
            . '</div><label class="ojp-check"><input type="checkbox" name="tls_enabled" value="1"'
            . (($current?->tlsEnabled() ?? false) ? ' checked' : '')
            . '><span>TLS 사용</span></label>'
            . '<p class="ojp-field-help">단일 계정을 사용하려면 읽기·쓰기 계정 이름을 같게 입력합니다.'
            . ' 비밀번호를 비우면 현재 저장된 값을 유지합니다.</p>'
            . '<fieldset class="ojp-field"><legend>기존 제출 기록의 시간 기준</legend>'
            . '<label class="ojp-check"><input type="checkbox" name="utc_storage_confirmed" value="1" required'
            . (($current?->utcStorageConfirmed() ?? false) ? ' checked' : '')
            . '><span>기존 제출 일시가 UTC로 저장되어 있음을 확인했습니다. '
            . '(제출 기록이 없는 새 데이터베이스 포함)</span></label>'
            . '<p class="ojp-field-help">solution.in_date에는 시간대가 없습니다. '
            . '기억하는 제출 시각과 원본 값을 비교해 확인하세요. '
            . '지역 시간으로 저장된 기록은 백업 후 UTC로 변환하고 확인해야 합니다. '
            . '이 설정은 기존 기록을 자동으로 변환하지 않습니다.</p></fieldset>'
            . '<button class="ojp-button" type="submit">후보 연결 검사</button></form>'
            . '</section><aside class="ojp-section">' . $apply
            . '<dl class="ojp-description-grid"><div><dt>핵심 채점 테이블</dt>'
            . '<dd>problem, solution, source_code, compileinfo, runtimeinfo, users, contest, contest_problem</dd>'
            . '</div><div><dt>제출 호환 테이블</dt><dd>source_code_user</dd></div>'
            . '<div><dt>사용하지 않는 테이블</dt><dd>sim, solution2</dd></div>'
            . '<div><dt>설치 확장 필드</dt><dd>bann, front, body, rear, langmask</dd></div>'
            . '<div><dt>DDL 범위</dt><dd>없는 확장 필드 추가만 허용</dd></div></dl></aside></div>';

        return $this->pages->respond(
            '저지 데이터베이스',
            $content,
            'operations.database',
            $request
        );
    }

    private function field(
        string $name,
        string $label,
        string $value,
        string $type = 'text',
        bool $required = false,
        bool $readonly = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="'
            . $this->escape($type) . '" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '"'
            . ($required ? ' required' : '')
            . ($readonly ? ' readonly' : '') . '></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
