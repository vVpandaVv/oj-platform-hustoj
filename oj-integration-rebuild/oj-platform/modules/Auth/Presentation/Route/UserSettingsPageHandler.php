<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureService;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageAvailability;
use CodingPanda\OjPlatform\Ui\Editor\AceCoreAssetLocator;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;

final class UserSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountService $accounts,
        private readonly AccountErasureService $erasures,
        private readonly UserPreferenceRepository $preferences,
        private readonly RegistrationPolicyProvider $registrationPolicy,
        private readonly SubmissionLanguageAvailability $languages,
        private readonly RouteUrlProvider $routes,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly AceCoreAssetLocator $aceAssets
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $account = $this->accounts->current();
        $identity = $account->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        $profile = $account->profile();
        $preference = $this->preferences->find($identity->userId())
            ?? new UserPreferences($identity->userId());
        $completion = $profile === null;
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">내 정보</p>'
            . '<h1>계정 설정</h1><p>프로필 공개 범위와 제출 환경을 관리합니다.</p></div>'
            . ($completion ? '' : '<a class="ojp-button" href="'
                . $this->escape($this->routes->path(
                    'auth.profile',
                    ['user_id' => $identity->userId()]
                )) . '">내 프로필 보기</a>')
            . '</header>';

        if ($completion) {
            $content .= '<div class="ojp-notice ojp-notice--warning"><strong>프로필 보완이 필요합니다.</strong>'
                . '<p>필수 정보를 저장하면 다른 페이지를 이용할 수 있습니다.</p></div>';
        }
        if (! $completion && ($profile?->mustChangePassword() ?? false)) {
            $content .= '<div class="ojp-notice ojp-notice--warning"><strong>비밀번호 변경이 필요합니다.</strong>'
                . '<p>가져온 계정의 기존 비밀번호를 계속 사용하고 있습니다. 아래에서 새 비밀번호로 변경해 주세요.</p></div>';
        }
        if ($this->registrationPolicy->current()->guardianConsentEnabled()) {
            $content .= $this->guardianRequestPanel($identity->email());
        }
        $content .= $this->settingsForm(
            $identity->userLogin(),
            $identity->email(),
            $identity->displayName(),
            $profile?->affiliation() ?? '',
            $profile?->birthDate() ?? '',
            $profile?->bio() ?? '',
            $preference,
            $completion
        );

        if (! $completion) {
            $content .= '<section class="ojp-section"><div class="ojp-section-heading"><div>'
                . '<h2>비밀번호</h2><p>현재 비밀번호를 확인한 뒤 새 비밀번호로 변경합니다.</p>'
                . '</div></div>' . $this->passwordForm() . '</section>'
                . '<section class="ojp-section"><div class="ojp-section-heading"><div>'
                . '<h2>계정 삭제</h2><p>소유 관계를 정리한 뒤 익명화 작업으로 처리합니다.</p>'
                . '</div></div>'
                . $this->erasureSection($identity->userId(), $request->queryString('mode'))
                . '</section>';
        }

        return $this->pages->authenticated('계정 설정', $content, $request);
    }

    private function settingsForm(
        string $userLogin,
        string $email,
        string $displayName,
        string $affiliation,
        string $birthDate,
        string $bio,
        UserPreferences $preferences,
        bool $completion
    ): string {
        return '<form class="ojp-form-stack" method="post" data-ojp-theme-preview action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_user_settings')
            . '<input type="hidden" name="mode" value="'
            . ($completion ? 'profile-completion' : 'profile') . '">'
            . '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>기본 정보</h2><p>로그인 아이디는 변경할 수 없습니다.</p></div></div>'
            . '<div class="ojp-form-grid">'
            . $this->field('로그인 아이디', 'user_login', 'text', $userLogin, false, 'username', true)
            . $this->field('이메일', 'email', 'email', $email, true, 'email')
            . $this->field('표시 이름', 'display_name', 'text', $displayName, true, 'name')
            . $this->field('소속', 'affiliation', 'text', $affiliation, false, 'organization')
            . $this->field(
                '생년월일',
                'birth_date',
                'date',
                $birthDate,
                $this->registrationPolicy->current()->guardianConsentEnabled(),
                'bday'
            )
            . '</div>'
            . '<label class="ojp-field"><span class="ojp-label">소개</span>'
            . '<textarea class="ojp-textarea" name="bio" rows="4" maxlength="1000">'
            . $this->escape($bio) . '</textarea></label>'
            . ($this->registrationPolicy->current()->guardianConsentEnabled()
                ? $this->field('법정대리인 확인 코드', 'guardian_code', 'text', '', false, 'one-time-code')
                    . '<p class="ojp-field-help">생년월일 변경으로 동의가 필요해지는 경우에만 입력합니다.</p>'
                : '')
            . '</section>'
            . '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>공개 범위</h2><p>비공개로 바꾸면 본인과 사용자 운영자만 확인할 수 있습니다.</p>'
            . '</div></div><div class="ojp-settings-list">'
            . $this->visibilitySwitch(
                '프로필 공개',
                '표시 이름, 소속과 소개를 로그인한 사용자에게 공개합니다.',
                'profile_visibility',
                $preferences->profileVisibility()
            )
            . $this->visibilitySwitch(
                '학습 활동 공개',
                '해결 문제 수, 정답률과 최근 해결 기록을 프로필에 표시합니다.',
                'activity_visibility',
                $preferences->activityVisibility()
            )
            . '</div></section>'
            . '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>제출 환경</h2><p>문제 제출 화면에서 처음 사용할 편집 환경입니다.</p>'
            . '</div></div><div class="ojp-form-grid">'
            . $this->languageSelect($preferences->defaultLanguage())
            . $this->themeSelect($preferences->editorTheme())
            . $this->select('편집기 글자 크기', 'editor_font_size', [
                '14' => '14px',
                '16' => '16px',
                '18' => '18px',
            ], (string) $preferences->editorFontSize())
            . '</div>'
            . $this->editorChoice($preferences->defaultEditor())
            . $this->inlineSubmissionPanelSwitch($preferences->inlineSubmissionPanel())
            . $this->codeTemplateField($preferences)
            . '</section>'
            . '<div class="ojp-sticky-actions">'
            . '<button class="ojp-button ojp-button--primary" type="submit">변경사항 저장</button></div>'
            . '</form>';
    }

    private function guardianRequestPanel(string $email): string
    {
        return '<section class="ojp-section"><details><summary><strong>법정대리인 동의 요청</strong></summary>'
            . '<p>생년월일 변경으로 동의가 필요할 때 요청을 보내고, 자녀 이메일로 받은 확인 코드를 기본 정보에 입력합니다.</p>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_send_guardian_consent_request')
            . '<input type="hidden" name="context" value="settings">'
            . '<div class="ojp-form-grid">'
            . $this->field('사용자 이메일', 'child_email', 'email', $email, true, 'email')
            . $this->field('법정대리인 이름', 'guardian_name', 'text', '', true, 'name')
            . $this->field('법정대리인 이메일', 'guardian_email', 'email', '', true, 'email')
            . '</div><button class="ojp-button" type="submit">동의 요청 보내기</button>'
            . '</form></details></section>';
    }

    private function visibilitySwitch(
        string $label,
        string $description,
        string $name,
        string $value
    ): string {
        return '<div class="ojp-setting-row"><div><strong>' . $this->escape($label)
            . '</strong><p>' . $this->escape($description) . '</p></div>'
            . '<label class="ojp-switch"><input type="hidden" name="' . $this->escape($name)
            . '" value="hidden"><input type="checkbox" name="' . $this->escape($name)
            . '" value="members"' . ($value === 'members' ? ' checked' : '')
            . '><span>공개</span></label></div>';
    }

    private function editorChoice(string $selected): string
    {
        $items = '';
        foreach (['code' => '코드 편집기', 'blockly' => 'Blockly'] as $value => $label) {
            $items .= '<label><input type="radio" name="default_editor" value="'
                . $value . '"' . ($selected === $value ? ' checked' : '')
                . '><span>' . $this->escape($label) . '</span></label>';
        }

        return '<div class="ojp-field ojp-segmented-field" role="radiogroup" aria-label="기본 제출 편집기">'
            . '<span class="ojp-label">기본 제출 편집기</span>'
            . '<div class="ojp-segmented-field__options">' . $items . '</div></div>';
    }

    private function inlineSubmissionPanelSwitch(bool $enabled): string
    {
        return '<div class="ojp-setting-row"><div><strong>문제 화면에서 제출 작성 창 함께 보기</strong>'
            . '<p>문제를 읽으면서 바로 코드를 작성·제출할 수 있도록 문제 화면에 작성 창을 같이 표시합니다.</p></div>'
            . '<label class="ojp-switch"><input type="hidden" name="inline_submission_panel" value="0">'
            . '<input type="checkbox" name="inline_submission_panel" value="1"'
            . ($enabled ? ' checked' : '') . '><span>사용</span></label></div>';
    }

    private function codeTemplateField(UserPreferences $preferences): string
    {
        $theme = $preferences->editorTheme();
        $template = $preferences->codeTemplate() ?? '';
        $editorClass = 'ojp-textarea ojp-code-editor ojp-code-editor--fallback'
            . (AceEditorThemeCatalog::isDark($theme)
                ? ' ojp-code-editor--dark'
                : ' ojp-code-editor--light')
            . ' ojp-code-editor--font-' . $preferences->editorFontSize();
        $assetJson = json_encode(
            $this->aceAssets->urls(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (! is_string($assetJson)) {
            $assetJson = '{}';
        }

        return '<div class="ojp-field"><span class="ojp-label" id="ojp-code-template-label">'
            . '기본 코드 템플릿</span>'
            . '<div class="ojp-ace-editor-shell ojp-ace-editor-shell--compact" data-oj-ace-editor'
            . ' data-assets="' . $this->escape($assetJson) . '"'
            . ' data-oj-ace-theme="' . $this->escape($theme) . '"'
            . ' data-oj-ace-font-size="' . $preferences->editorFontSize() . '">'
            . '<div class="ojp-ace-editor-surface" data-oj-ace-surface'
            . ' aria-labelledby="ojp-code-template-label"></div>'
            . '<textarea class="' . $editorClass . '" name="code_template" rows="8"'
            . ' spellcheck="false" data-oj-source-editor>'
            . $this->escape($template) . '</textarea>'
            . '<p class="ojp-field-help ojp-ace-editor-status" data-oj-ace-status'
            . ' role="status" hidden></p></div>'
            . '<span class="ojp-field-help">새 코드 제출을 열 때 기본값으로 사용합니다.</span></div>';
    }

    private function languageSelect(?int $selected): string
    {
        $allowed = $this->languages->allowedForSite();
        if ($selected !== null && ! isset($allowed[$selected])) {
            $selected = null;
        }
        $options = '<option value=""' . ($selected === null ? ' selected' : '')
            . '>문제 설정 사용</option>';
        foreach ($allowed as $id => $label) {
            $options .= '<option value="' . (int) $id . '"'
                . ($selected === (int) $id ? ' selected' : '') . '>'
                . $this->escape($label) . '</option>';
        }

        return '<label class="ojp-field"><span class="ojp-label">기본 제출 언어</span>'
            . '<select class="ojp-select" name="default_language">' . $options
            . '</select></label>';
    }

    private function themeSelect(string $selected): string
    {
        $options = '';
        foreach (AceEditorThemeCatalog::groups() as $group => $themes) {
            $options .= '<optgroup label="' . $this->escape($group) . '">';
            foreach ($themes as $value => $label) {
                $options .= '<option value="' . $this->escape($value) . '"'
                    . ($selected === $value ? ' selected' : '') . '>'
                    . $this->escape($label) . '</option>';
            }
            $options .= '</optgroup>';
        }

        return '<label class="ojp-field"><span class="ojp-label">편집기 테마</span>'
            . '<select class="ojp-select" name="editor_theme" data-ojp-theme-select>' . $options
            . '</select></label>';
    }

    /** @param array<string, string> $options */
    private function select(string $label, string $name, array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $optionLabel) {
            // Numeric-string keys (e.g. '14') are cast to int by PHP arrays,
            // so $value must be normalized back to string before use.
            $value = (string) $value;
            $html .= '<option value="' . $this->escape($value) . '"'
                . ($selected === $value ? ' selected' : '') . '>'
                . $this->escape($optionLabel) . '</option>';
        }

        return '<label class="ojp-field"><span class="ojp-label">' . $this->escape($label)
            . '</span><select class="ojp-select" name="' . $this->escape($name)
            . '">' . $html . '</select></label>';
    }

    private function passwordForm(): string
    {
        return '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_change_password')
            . $this->field('현재 비밀번호', 'current_password', 'password', '', true, 'current-password')
            . $this->field('새 비밀번호', 'new_password', 'password', '', true, 'new-password')
            . $this->field('새 비밀번호 확인', 'new_password_confirm', 'password', '', true, 'new-password')
            . '<button class="ojp-button ojp-button--primary" type="submit">비밀번호 변경</button>'
            . '</form>';
    }

    private function erasureSection(int $userId, string $mode): string
    {
        try {
            $impact = $this->erasures->impact($userId);
        } catch (\Throwable) {
            return '<div class="ojp-notice ojp-notice--danger">계정 정리 상태를 확인하지 못했습니다. 잠시 뒤 다시 시도해 주세요.</div>';
        }

        if (! $impact->canRequest()) {
            $items = '';
            foreach ($impact->blockers() as $blocker) {
                $items .= '<li>' . $this->escape($blocker->label()) . ' '
                    . $blocker->count() . '건</li>';
            }

            return '<div class="ojp-notice ojp-notice--warning">아래 항목을 다른 운영자에게 이관하거나 정리해야 합니다.</div>'
                . '<ul>' . $items . '</ul>';
        }

        return '<details class="ojp-danger-details"' . ($mode === 'erasure' ? ' open' : '')
            . '><summary>계정 삭제 요청</summary>'
            . '<p>완료되면 로그인 정보와 개인 설정이 익명화되고 모든 세션이 종료됩니다. 복원할 수 없습니다.</p>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_delete_account')
            . $this->field('현재 비밀번호', 'current_password', 'password', '', true, 'current-password')
            . '<label class="ojp-check"><input type="checkbox" name="confirm_irreversible" value="1" required>'
            . '<span>계정 삭제 후 복원할 수 없음을 확인했습니다.</span></label>'
            . $this->field('확인 문구: 계정 삭제', 'confirmation_text', 'text', '')
            . '<button class="ojp-button ojp-button--danger" type="submit">계정 삭제 요청</button>'
            . '</form></details>';
    }

    private function field(
        string $label,
        string $name,
        string $type,
        string $value,
        bool $required = true,
        string $autocomplete = '',
        bool $readonly = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="'
            . $this->escape($type) . '" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '"'
            . ($autocomplete === '' ? '' : ' autocomplete="' . $this->escape($autocomplete) . '"')
            . ($required ? ' required' : '') . ($readonly ? ' readonly' : '')
            . '></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
