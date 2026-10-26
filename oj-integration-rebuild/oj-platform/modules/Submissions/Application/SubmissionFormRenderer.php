<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use JsonException;

/**
 * Renders the code-submission form body (policy summary, language/editor
 * mode controls, code or code-completion editor, submit button) shared by
 * the standalone /submit/{problemId} page and the inline panel embedded on
 * the problem detail page.
 */
final class SubmissionFormRenderer
{
    public const ACTION = 'oj_submit_submission';

    public function __construct(
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionLanguageCatalog $languages,
        private readonly SubmissionCodeEditorAssets $codeEditorAssets,
        private readonly SubmissionEditorExtensionRegistry $editors,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionHtml $html
    ) {
    }

    /**
     * @param array{
     *   contest_id: int,
     *   contest_problem_number: int,
     *   class_id: ?int,
     *   group_id: ?int
     * } $formContext
     */
    public function render(
        int $problemId,
        SubmissionCodePolicy $policy,
        ?string $forcedBlocklyProfile,
        UserSubmissionPreferences $preferences,
        array $formContext,
        ?int $actorUserId = null,
        ?array $prefill = null
    ): string {
        $settings = $this->settings->current();
        return ($prefill === null ? '' : '<div class="ojp-notice ojp-notice--info" role="status">초안을 불러왔습니다. 코드와 언어를 확인한 후 제출하기를 눌러 주세요.</div>')
            . $this->policySummary($policy)
            . '<form class="ojp-form-stack ojp-submission-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-oj-submission-form'
            . ' data-oj-code-copy="' . ($settings->allowCodeCopy() ? '1' : '0') . '"'
            . ' data-oj-code-paste="' . ($settings->allowCodePaste() ? '1' : '0') . '"'
            . ($prefill === null ? '' : ' data-oj-handoff-storage="' . $this->html->escape($prefill['storage_key']) . '"') . '>'
            . $this->security->hiddenFields(self::ACTION)
            . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
            . '<input type="hidden" name="request_key" value="'
            . bin2hex(random_bytes(32)) . '">'
            . $this->contextFields($formContext)
            . match ($policy->entryMode()) {
                'code_completion' => $this->completionEditor($policy),
                'code_ordering' => $this->orderingEditor($policy, $formContext, $actorUserId),
                default => $this->fullCodeEditor($policy, $forcedBlocklyProfile, $preferences, $prefill),
            }
            . '<div class="ojp-form-actions"><button class="ojp-button ojp-button--primary"'
            . ($policy->entryMode() === 'code_ordering'
                ? ' data-oj-ordering-submit disabled'
                : '')
            . ' type="submit">제출하기</button></div></form>';
    }

    private function fullCodeEditor(
        SubmissionCodePolicy $policy,
        ?string $forcedBlocklyProfile,
        UserSubmissionPreferences $preferences,
        ?array $prefill = null
    ): string
    {
        $settings = $this->settings->current();
        $allowed = $this->languages->allowed(
            $settings->disabledLanguageMask(),
            $policy->disabledLanguageMask()
        );
        $blockly = $this->editors->find('blockly');
        $blocklySelected = $prefill === null && $blockly !== null
            && $preferences->defaultEditor() === 'blockly';
        $modes = '<label class="ojp-segmented__item"><input type="radio"'
            . ' name="submission_mode" value="code"'
            . ($blocklySelected ? '' : ' checked') . '>'
            . '<span>코드</span></label>';
        if ($blockly !== null) {
            $modes .= '<label class="ojp-segmented__item"><input type="radio"'
                . ' name="submission_mode" value="blockly"'
                . ($blocklySelected ? ' checked' : '') . '>'
                . '<span>' . $this->html->escape($blockly->label())
                . '</span></label>';
        }
        $defaultLanguage = $prefill['language_id'] ?? $preferences->defaultLanguage();
        if ($defaultLanguage === null || ! isset($allowed[$defaultLanguage])) {
            $defaultLanguage = array_key_first($allowed);
        }
        $options = '';
        foreach ($allowed as $languageId => $label) {
            $options .= '<option value="' . $languageId . '"'
                . ' data-oj-ace-mode="'
                . $this->html->escape($this->languages->editorMode($languageId))
                . '"'
                . ($languageId === SubmissionLanguageCatalog::BLOCKLY_PYTHON_ID
                    ? ' data-oj-python-language'
                    : '')
                . ($languageId === $defaultLanguage ? ' selected' : '')
                . '>' . $this->html->escape($label) . '</option>';
        }
        $initialSource = $prefill['source'] ?? ($policy->starterCode() !== ''
            ? $policy->starterCode()
            : ($preferences->codeTemplate() ?? ''));
        $theme = $preferences->editorTheme();
        $editorClass = 'ojp-textarea ojp-code-editor ojp-code-editor--fallback'
            . (AceEditorThemeCatalog::isDark($theme)
                ? ' ojp-code-editor--dark'
                : ' ojp-code-editor--light')
            . ' ojp-code-editor--font-' . $preferences->editorFontSize();
        $assetUrls = $this->codeEditorAssets->isReady()
            ? $this->codeEditorAssets->urls()
            : [];
        $assetJson = json_encode(
            $assetUrls,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (! is_string($assetJson)) {
            $assetJson = '{}';
        }
        $sourceLabelId = 'ojp-source-label-' . $policy->problemId();
        $directEditor = $options === ''
            ? '<div class="ojp-notice ojp-notice--warning" role="status">'
                . '<strong>허용된 직접 제출 언어가 없습니다.</strong>'
                . '<p>제출 설정과 문제 언어 설정을 확인해 주세요.</p></div>'
            : '<label class="ojp-field"><span class="ojp-label">언어</span>'
                . '<select class="ojp-select" name="language_id"'
                . ' data-oj-language-select required>' . $options
                . '</select></label><div class="ojp-field">'
                . '<span class="ojp-label" id="' . $sourceLabelId
                . '">소스 코드</span>'
                . '<div class="ojp-ace-editor-shell" data-oj-ace-editor'
                . ' data-assets="' . $this->html->escape($assetJson) . '"'
                . ' data-oj-ace-theme="' . $this->html->escape($theme) . '"'
                . ' data-oj-ace-font-size="' . $preferences->editorFontSize()
                . '"><div class="ojp-ace-editor-surface"'
                . ' data-oj-ace-surface aria-labelledby="' . $sourceLabelId
                . '"></div>'
                . '<textarea class="' . $editorClass . '" name="source"'
                . ' rows="22" spellcheck="false" data-oj-source-editor>'
                . ($prefill === null ? '' : "\n")
                . $this->html->escape($initialSource)
                . '</textarea><p class="ojp-field-help ojp-ace-editor-status"'
                . ' data-oj-ace-status role="status" hidden></p></div></div>';

        return '<fieldset class="ojp-fieldset"><legend>작성 방식</legend>'
            . '<div class="ojp-segmented" data-oj-submission-mode>'
            . $modes . '</div></fieldset>'
            . '<section class="ojp-submission-editor" data-oj-mode-panel="code"'
            . ($blocklySelected ? ' hidden' : '') . '>'
            . $directEditor . '</section>'
            . ($blockly === null
                ? ''
                : '<section class="ojp-submission-editor"'
                    . ' data-oj-mode-panel="blockly"'
                    . ($blocklySelected ? '' : ' hidden') . '>'
                    . $blockly->render($policy, $forcedBlocklyProfile) . '</section>');
    }

    /**
     * @param array{
     *   contest_id: int,
     *   contest_problem_number: int,
     *   class_id: ?int,
     *   group_id: ?int
     * } $context
     */
    private function contextFields(array $context): string
    {
        if ($context['contest_id'] > 0) {
            return '<input type="hidden" name="contest_id" value="'
                . $context['contest_id'] . '"><input type="hidden"'
                . ' name="contest_problem_number" value="'
                . $context['contest_problem_number'] . '">';
        }
        if ($context['class_id'] !== null) {
            return '<input type="hidden" name="class_id" value="'
                . $context['class_id'] . '">';
        }
        if ($context['group_id'] !== null) {
            return '<input type="hidden" name="group_id" value="'
                . $context['group_id'] . '">';
        }

        return '';
    }

    private function completionEditor(SubmissionCodePolicy $policy): string
    {
        $schema = $this->completionSchema($policy);
        if ($schema === null) {
            return '<div class="ojp-notice ojp-notice--danger" role="alert">'
                . '<strong>코드 빈칸을 불러오지 못했습니다.</strong>'
                . '<p>문제 관리자에게 코드 완성 설정을 확인해 달라고 알려 주세요.</p>'
                . '</div>';
        }
        $blanks = [];
        foreach ($schema['blanks'] as $blank) {
            $blanks[$blank['id']] = $blank;
        }
        $template = $policy->starterCode();
        preg_match_all(
            '/\{\{OJ_BLANK:([a-zA-Z0-9_-]{1,64})\}\}/',
            $template,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $body = '';
        $cursor = 0;
        foreach ($matches[0] ?? [] as $index => $token) {
            $position = (int) $token[1];
            $body .= '<span class="ojp-completion-code">'
                . $this->html->escape(
                    substr($template, $cursor, $position - $cursor)
                ) . '</span>';
            $id = (string) ($matches[1][$index][0] ?? '');
            $blank = $blanks[$id] ?? null;
            if (! is_array($blank)) {
                continue;
            }
            $label = $this->html->escape((string) $blank['label']);
            $name = 'completion_answers[' . $this->html->escape($id) . ']';
            if ($blank['kind'] === 'block') {
                $body .= '<label class="ojp-completion-blank ojp-completion-blank--block">'
                    . '<span class="ojp-sr-only">' . $label . '</span>'
                    . '<textarea name="' . $name . '" rows="3" required'
                    . ' maxlength="' . (int) $blank['max_bytes']
                    . '" placeholder="' . $label . '"></textarea></label>';
            } else {
                $body .= '<label class="ojp-completion-blank">'
                    . '<span class="ojp-sr-only">' . $label . '</span>'
                    . '<input type="text" name="' . $name . '" required'
                    . ' maxlength="' . (int) $blank['max_bytes']
                    . '" placeholder="' . $label . '"></label>';
            }
            $cursor = $position + strlen((string) $token[0]);
        }
        $body .= '<span class="ojp-completion-code">'
            . $this->html->escape(substr($template, $cursor)) . '</span>';

        return '<input type="hidden" name="submission_mode" value="code_completion">'
            . '<input type="hidden" name="language_id" value="0">'
            . '<input type="hidden" name="template_hash" value="'
            . $this->html->escape((string) $policy->completionHash()) . '">'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>코드 빈칸 완성</h2>'
            . '<p>밝게 표시된 영역을 선택해 필요한 코드만 입력합니다.</p></div>'
            . '</div><div class="ojp-completion-editor"'
            . ' data-oj-completion-editor>' . $body . '</div></section>';
    }

    /** @param array{contest_id: int, contest_problem_number: int, class_id: ?int, group_id: ?int} $context */
    private function orderingEditor(SubmissionCodePolicy $policy, array $context, ?int $actorUserId): string
    {
        try {
            $schema = (string) $policy->orderingSchema();
            $blocks = CodeOrderingPuzzle::publicBlocks($schema);
            $languageId = $this->languages->idForKey(CodeOrderingPuzzle::languageKey($schema));
            $hash = (string) $policy->orderingHash();
            if (! hash_equals(CodeOrderingPuzzle::hash($schema), $hash)) {
                throw new \DomainException('코드 순서 배열 설정이 변경되었습니다.');
            }
        } catch (\Throwable) {
            return '<div class="ojp-notice ojp-notice--danger" role="alert">'
                . '<strong>코드 조각을 불러오지 못했습니다.</strong>'
                . '<p>문제 관리자에게 코드 순서 배열 설정을 확인해 달라고 알려 주세요.</p></div>';
        }
        $items = '';
        foreach ($blocks as $block) {
            $id = $this->html->escape($block['id']);
            $items .= '<li class="ojp-code-ordering__block" data-oj-ordering-block-id="'
                . $id . '" tabindex="0" aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown">'
                . '<span class="ojp-code-ordering__position" data-oj-ordering-position></span>'
                . '<button class="ojp-code-ordering__handle" type="button" draggable="true"'
                . ' data-oj-ordering-handle aria-label="이 코드 조각을 끌어서 이동" title="끌어서 이동 · Alt + 위·아래 방향키">⠿</button>'
                . '<pre class="ojp-code-ordering__code" data-oj-ordering-display-normalized><code>'
                // Every server block has a structural final LF; display it without an empty terminal row.
                . $this->html->escape(preg_replace('/\n\z/', '', $block['code']) ?? $block['code'])
                . '</code></pre>'
                . '<div class="ojp-code-ordering__controls">'
                . '<button class="ojp-button ojp-button--small" type="button"'
                . ' data-oj-ordering-move="up" aria-label="이 코드 조각을 위로 이동" title="위로 이동" disabled>↑</button>'
                . '<button class="ojp-button ojp-button--small" type="button"'
                . ' data-oj-ordering-move="down" aria-label="이 코드 조각을 아래로 이동" title="아래로 이동" disabled>↓</button>'
                . '</div>'
                . '<input type="hidden" name="ordering_block_ids[]" value="' . $id . '"></li>';
        }
        // The key contains the public revision and context, never the canonical block order.
        $storageKey = 'oj_submission_ordering_' . max(0, $actorUserId ?? 0)
            . '_' . $policy->problemId() . '_' . $hash
            . '_' . $context['contest_id'] . '_' . $context['contest_problem_number']
            . '_' . ($context['class_id'] ?? 0) . '_' . ($context['group_id'] ?? 0);

        return '<input type="hidden" name="submission_mode" value="code_ordering">'
            . '<input type="hidden" name="language_id" value="' . $languageId . '">'
            . '<input type="hidden" name="source" value="">'
            . '<input type="hidden" name="template_hash" value="'
            . $this->html->escape($hash) . '">'
            . '<section class="ojp-section ojp-code-ordering" data-oj-code-ordering'
            . ' data-storage-key="' . $this->html->escape($storageKey) . '">'
            . '<div class="ojp-section-heading"><div><h2>코드 순서 배열</h2>'
            . '<p>코드 조각을 실행할 순서로 배열하세요. 각 조각은 한 번씩 사용하며 내용과 들여쓰기는 바꾸지 않습니다.</p>'
            . '<p class="ojp-field-help">조각을 끌어 놓거나 위로·아래로 버튼을 누르세요. '
            . '키보드에서는 조각에 초점을 두고 Alt + 위·아래 방향키로 이동합니다.</p>'
            . '</div></div><ol class="ojp-code-ordering__list" data-oj-ordering-list>'
            . $items . '</ol><p class="ojp-field-help" role="status" aria-live="polite"'
            . ' data-oj-ordering-status>코드 조각을 준비하고 있습니다.</p>'
            . '<noscript><p>코드 조각을 배열하려면 브라우저에서 JavaScript를 켜 주세요.</p></noscript>'
            . '</section>';
    }

    private function policySummary(SubmissionCodePolicy $policy): string
    {
        $forbidden = $policy->forbiddenFragments();
        $forbiddenHtml = $forbidden === []
            ? '<span class="ojp-badge">없음</span>'
            : implode('', array_map(
                fn (string $fragment): string => '<code class="ojp-code-chip">'
                    . $this->html->escape($fragment) . '</code>',
                $forbidden
            ));
        $fixedCode = '';
        foreach ([
            '제출 코드 앞에 붙는 코드' => $policy->prefixCode(),
            '제출 코드 뒤에 붙는 코드' => $policy->suffixCode(),
        ] as $label => $code) {
            if ($code === '') {
                continue;
            }
            $wrapper = new SubmissionWrapperCode($code);
            $visible = $wrapper->visibleCode();
            $fixedCode .= '<details class="ojp-disclosure"><summary>'
                . $label . '</summary>'
                . ($wrapper->hasHiddenLines() && trim($visible) === ''
                    ? '<p class="ojp-muted">비공개 코드</p>'
                    : '<pre class="ojp-code-block"><code>'
                        . $this->html->escape($visible) . '</code></pre>')
                . '</details>';
        }

        return '<section class="ojp-section ojp-submission-policy">'
            . '<div class="ojp-description-grid"><div><dt>작성 방식</dt><dd>'
            . match ($policy->entryMode()) {
                'code_completion' => '코드 빈칸 완성',
                'code_ordering' => '코드 순서 배열',
                default => '전체 코드',
            }
            . '</dd></div><div><dt>최대 코드 크기</dt><dd>'
            . number_format((int) floor(
                $this->settings->current()->maxSourceBytes() / 1024
            )) . 'KB</dd></div><div class="ojp-description-grid__wide">'
            . '<dt>제출 금지어</dt><dd class="ojp-inline-list">'
            . $forbiddenHtml . '</dd></div></div>' . $fixedCode . '</section>';
    }

    /**
     * @return array{
     *   language_key: string,
     *   blanks: list<array{id: string, label: string, kind: string, max_bytes: int}>
     * }|null
     */
    private function completionSchema(
        SubmissionCodePolicy $policy
    ): ?array {
        try {
            $decoded = json_decode(
                (string) $policy->completionSchema(),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded)
            && is_array($decoded['blanks'] ?? null)
                ? $decoded
                : null;
    }
}
