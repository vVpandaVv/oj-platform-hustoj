<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Presentation;

use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyProfileCatalog;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsProvider;
use CodingPanda\OjPlatform\Modules\Blockly\Contract\BlocklyAssets;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEditorExtension;

final class BlocklyEditorExtension implements SubmissionEditorExtension
{
    public function __construct(
        private readonly BlocklySettingsProvider $settings,
        private readonly BlocklyProfileCatalog $profiles,
        private readonly BlocklyAssets $assets
    ) {
    }

    public function key(): string
    {
        return 'blockly';
    }

    public function label(): string
    {
        return '블록';
    }

    public function isAvailable(): bool
    {
        return $this->settings->current()->isEnabled()
            && $this->assets->isReady();
    }

    public function render(
        SubmissionCodePolicy $policy,
        ?string $forcedProfile = null
    ): string {
        $settings = $this->settings->current();
        $profile = $forcedProfile === null
            ? $settings->defaultProfile()
            : strtolower(trim($forcedProfile));
        $this->profiles->assertProfile($profile);
        $profileOptions = '';
        foreach ($this->profiles->profiles() as $key => $label) {
            if ($forcedProfile !== null && $key !== $profile) {
                continue;
            }
            $profileOptions .= '<option value="' . $key . '"'
                . ($profile === $key ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }
        $assets = json_encode(
            $this->assets->urls(),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );
        $contract = json_encode(
            $this->profiles->clientContract(
                $profile
            ),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        return '<div class="ojp-blockly-editor" data-oj-blockly-editor'
            . ' data-problem-id="' . $policy->problemId() . '"'
            . ' data-assets="' . $this->escape($assets) . '"'
            . ' data-contract="' . $this->escape($contract) . '"'
            . ' data-maximum-bytes="' . $settings->maximumWorkspaceBytes()
            . '"><div class="ojp-blockly-toolbar">'
            . '<label class="ojp-field"><span class="ojp-label">블록 프로필</span>'
            . '<select class="ojp-select" data-oj-blockly-profile'
            . ($forcedProfile === null ? '' : ' disabled aria-disabled="true"')
            . '>' . $profileOptions . '</select></label>'
            . '<div class="ojp-inline-actions">'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-oj-blockly-import>불러오기</button>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-oj-blockly-export>내보내기</button>'
            . '<input class="ojp-visually-hidden" type="file"'
            . ' accept=".ojblocks.json,application/json"'
            . ' data-oj-blockly-file></div></div>'
            . '<div class="ojp-blockly-layout">'
            . '<div class="ojp-blockly-workspace" data-oj-blockly-workspace'
            . ' aria-label="Blockly 코드 편집기"></div>'
            . '<details class="ojp-blockly-code" open>'
            . '<summary>생성 코드</summary><pre><code'
            . ' data-oj-blockly-code>Blockly를 불러오는 중입니다.</code></pre>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-oj-blockly-to-code>코드로 전환</button>'
            . '</details></div>'
            . '<p class="ojp-field-help" data-oj-blockly-status role="status">'
            . '블록 모드를 선택하면 로컬 편집기를 불러옵니다.</p>'
            . '<textarea name="blockly_workspace" hidden'
            . ' data-oj-blockly-payload></textarea>'
            . '<input type="hidden" name="blockly_profile" value="'
            . $this->escape($profile)
            . '" data-oj-blockly-profile-value></div>';
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
