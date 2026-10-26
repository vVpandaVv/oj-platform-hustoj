<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyProfileCatalog;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsProvider;
use CodingPanda\OjPlatform\Modules\Blockly\Contract\BlocklyAssets;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;

final class BlocklySettingsPageHandler implements RouteHandler
{
    /**
     * @var array<string, string>
     */
    private const ASSET_LABELS = [
        'core' => '편집기 엔진',
        'blocks' => '기본 블록',
        'messages' => '한글 문구',
        'python' => 'Python 코드 생성',
        'editor' => '사이트 편집기 연결',
    ];

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SettingsManager $settings,
        private readonly BlocklySettingsProvider $blocklySettings,
        private readonly BlocklyProfileCatalog $profiles,
        private readonly BlocklyAssets $assets,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->read('blockly', []);
        $current = $this->blocklySettings->current();
        $profileOptions = '';
        foreach ($this->profiles->profiles() as $key => $label) {
            $profileOptions .= '<option value="' . $key . '"'
                . ($current->defaultProfile() === $key ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }
        $diagnostics = '';
        foreach ($this->assets->diagnostics() as $key => $diagnostic) {
            $diagnostics .= '<tr><td>'
                . $this->escape(self::ASSET_LABELS[$key] ?? '기타 파일')
                . '</td><td>' . $this->escape($diagnostic['path'])
                . '</td><td>' . (
                    $diagnostic['exists'] && $diagnostic['integrity']
                    ? '<span class="ojp-badge ojp-badge--success">정상</span>'
                    : '<span class="ojp-badge ojp-badge--danger">'
                        . ($diagnostic['exists'] ? '무결성 오류' : '누락')
                        . '</span>'
                )
                . '</td><td>' . number_format($diagnostic['bytes'])
                . 'B</td><td><code>'
                . ($diagnostic['sha256'] === ''
                    ? '-'
                    : $this->escape(substr($diagnostic['sha256'], 0, 12)))
                . '</code></td></tr>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>블록 코딩</h1>'
            . '<p>공통 블록 편집기와 Python 코드 생성 설정을 관리합니다.</p>'
            . '</div></header><section class="ojp-section">'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_blockly_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '">'
            . '<label class="ojp-check"><input type="checkbox" name="enabled"'
            . ' value="1"' . ($current->isEnabled() ? ' checked' : '')
            . '><span>Blockly 제출 사용</span></label>'
            . '<div class="ojp-form-grid"><label class="ojp-field">'
            . '<span class="ojp-label">기본 프로필</span>'
            . '<select class="ojp-select" name="default_profile">'
            . $profileOptions . '</select></label><label class="ojp-field">'
            . '<span class="ojp-label">작업공간 최대 크기(KB)</span>'
            . '<input class="ojp-input" type="number" min="64" max="1024"'
            . ' name="maximum_workspace_kb" value="'
            . (int) floor($current->maximumWorkspaceBytes() / 1024)
            . '"></label></div><dl class="ojp-description-grid">'
            . '<div><dt>Blockly 엔진</dt><dd>'
            . BlocklyProfileCatalog::ENGINE_VERSION . '</dd></div>'
            . '<div><dt>사용 가능 블록 버전</dt><dd>'
            . BlocklyProfileCatalog::REGISTRY_VERSION . '</dd></div>'
            . '<div><dt>Python 생성기</dt><dd>'
            . BlocklyProfileCatalog::GENERATOR_VERSION . '</dd></div></dl>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . 'Blockly 설정 저장</button></form></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>Blockly 파일 점검</h2>'
            . '<p>외부 서버에 의존하지 않고 플러그인에 포함된 파일을 사용합니다.</p></div>'
            . '</div><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>자산</th><th>경로</th><th>상태</th>'
            . '<th>크기</th><th>SHA-256</th></tr></thead><tbody>'
            . $diagnostics . '</tbody></table></div></section>';

        return $this->pages->respond(
            '블록 코딩',
            $content,
            'submissions.blockly',
            $request
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
