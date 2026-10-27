<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use CodingPanda\OjPlatform\Ui\Editor\RichTextEditor;
use CodingPanda\OjPlatform\Ui\Editor\RichTextImageUpload;

final class ProblemEditForm
{
    public function __construct(
        private readonly ProblemTopicDictionary $topics,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html,
        private readonly JudgeLanguageCatalog $languages
    ) {
    }

    public function render(
        ?JudgeProblem $problem,
        ?ProblemMetadata $metadata,
        int $proposedProblemId,
        bool $canUseAi = false,
        ?array $metadataDraft = null,
        ?array $authoringDraft = null
    ): string {
        $problemId = $problem?->id()->value() ?? 0;
        $title = $problem?->title() ?? '';
        $visibility = $problem?->visibility() ?? ProblemVisibility::Archived;
        $mode = $metadata?->codeEntryMode() ?? CodeEntryMode::FullCode;
        $selectedTopics = $metadata?->topicKeys() ?? [];
        $schema = $metadata?->completionSchema() ?? $this->emptySchema();

        return $this->draftReviews(
            $problem,
            $metadata,
            $metadataDraft,
            $authoringDraft
        )
            . ($canUseAi
                ? $this->aiAuthoringForm($problem, $metadata)
                : '')
            . '<form class="ojp-form ojp-problem-editor" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-ojp-problem-editor>'
            . $this->security->hiddenFields('oj_problem_save')
            . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
            . '<div class="ojp-editor-sticky-bar"><span>문제 번호 <strong>'
            . ($problemId > 0 ? $problemId : $proposedProblemId)
            . '</strong></span><div class="ojp-command-group">'
            . '<a class="ojp-button" href="/oj-console/problems">취소</a>'
            . '<button class="ojp-button ojp-button--primary" type="submit">저장</button>'
            . '</div></div>'
            . $this->section(
                '기본 정보',
                '<div class="ojp-form-grid">'
                . $this->input('title', '문제 제목', $title, true)
                . $this->input('source', '출처', $problem?->source() ?? '')
                . $this->number('time_limit', '시간 제한(초)', $problem?->timeLimitSeconds() ?? 1, 1, 3600)
                . $this->number('memory_limit', '메모리 제한(MB)', $problem?->memoryLimitMegabytes() ?? 128, 1, 1048576)
                . $this->select(
                    'visibility',
                    '공개 상태',
                    $visibility->value,
                    ['archived' => '보관', 'published' => '공개']
                )
                . '<label class="ojp-check"><input type="checkbox" name="special_judge" value="1"'
                . ($problem?->specialJudge() ? ' checked' : '')
                . '><span>특수 채점기 사용</span></label></div>'
            )
            . $this->section(
                '문제 내용',
                $this->richTextEditor(
                    'description',
                    '문제 설명',
                    $problem?->description() ?? '',
                    12,
                    $problemId,
                    true
                )
                . '<div class="ojp-form-grid">'
                . $this->richTextEditor('input', '입력', $problem?->input() ?? '', 8, $problemId)
                . $this->richTextEditor('output', '출력', $problem?->output() ?? '', 8, $problemId)
                . $this->textarea('sample_input', '예시 입력', $problem?->sampleInput() ?? '', 8)
                . $this->textarea('sample_output', '예시 출력', $problem?->sampleOutput() ?? '', 8)
                . '</div>'
                . $this->richTextEditor('hint', '힌트', $problem?->hint() ?? '', 6, $problemId)
                . '<p class="ojp-form-note">편집기에서 문단·목록·강조·코드·LaTeX·이미지를 작성할 수 있습니다. 이미지는 도구 모음의 "이미지" 버튼이나 붙여넣기·끌어다 놓기로 올립니다(PNG·JPEG·GIF·WebP, 5MB 이하). 예시 입출력은 입력한 텍스트 그대로 표시됩니다.</p>'
                . $this->textarea('credits', '크레딧', $problem?->credits() ?? '', 3)
                . '<p class="ojp-form-note">출제자, 출처 감사 인사 등을 입력하면 문제와 함께 표시됩니다.</p>'
            )
            . $this->section(
                '분류 정보',
                '<div class="ojp-form-grid">'
                . $this->select(
                    'difficulty_score',
                    '난이도(선택)',
                    (string) ($metadata?->difficulty()?->value() ?? ''),
                    [
                        '' => '미분석',
                        '1' => '1 · 입문',
                        '2' => '2 · 기초',
                        '3' => '3 · 보통',
                        '4' => '4 · 어려움',
                        '5' => '5 · 매우 어려움',
                    ]
                )
                . $this->topicSelector($selectedTopics)
                . '</div>'
                . $this->textarea('admin_notes', '관리자 메모', $metadata?->adminNotes() ?? '', 4)
            )
            . $this->section(
                '코드 제출 조건',
                '<div class="ojp-field ojp-segmented-field" role="radiogroup" aria-label="코드 작성 방식">'
                . '<span class="ojp-label">코드 작성 방식</span>'
                . '<div class="ojp-segmented-field__options">'
                . $this->modeOption('full_code', '전체 코드 작성', $mode)
                . $this->modeOption('code_completion', '코드 빈칸 완성', $mode)
                . $this->modeOption('code_ordering', '코드 순서 배열', $mode)
                . '</div></div>'
                . $this->languageSelector($problem?->languageMask())
                . $this->visualGradingSelector($metadata)
                . '<div class="ojp-code-ordering" data-ojp-code-ordering>'
                . $this->select('ordering_language_key', '코드 배열 언어',
                    $metadata?->orderingSchema() !== null ? CodeOrderingPuzzle::languageKey($metadata->orderingSchema()) : 'python',
                    ['python' => 'Python 3', 'c' => 'C', 'cpp' => 'C++', 'java' => 'Java'])
                . $this->textarea('ordering_source', '배열할 완성 코드 (교사용)',
                    $metadata?->orderingSchema() !== null ? CodeOrderingPuzzle::source($metadata->orderingSchema()) : '', 16)
                . '<p class="ojp-form-note">완성 코드를 논리 줄 단위로 나눠 섞습니다. 학생은 모든 조각을 한 번씩 배치하며 들여쓰기는 그대로 유지됩니다. 코드 배열 언어 하나로 제출 언어가 고정되고 기존 테스트로 채점합니다. 시작 코드에는 정답을 넣지 마세요.</p></div>'
                . $this->textarea(
                    'forbidden_code',
                    '제출 금지어 (/ 구분, \/는 문자 /)',
                    $problem?->forbiddenCode() ?? '',
                    5
                )
                . '<div class="ojp-form-grid">'
                . $this->textarea('prefix_code', '제출 코드 앞에 붙일 코드 (줄 끝 #hidden: 비공개)', $problem?->prefixCode() ?? '', 8)
                . $this->textarea('suffix_code', '제출 코드 뒤에 붙일 코드 (줄 끝 #hidden: 비공개)', $problem?->suffixCode() ?? '', 8)
                . '</div>'
                . '<div class="ojp-code-completion" data-ojp-code-completion>'
                . $this->completionLanguageSelector($schema)
                . $this->textarea('starter_code', '시작 코드 또는 빈칸 완성 템플릿', $problem?->starterCode() ?? '', 16)
                . '<div class="ojp-code-completion__toolbar">'
                . '<button class="ojp-button" type="button" data-ojp-add-blank>선택 영역을 빈칸으로 만들기</button>'
                . '<span class="ojp-form-note">코드를 선택한 뒤 빈칸으로 지정합니다.</span></div>'
                . '<div class="ojp-blank-list" data-ojp-blank-list></div>'
                . '<input type="hidden" name="code_completion_schema" value="'
                . $this->html->escape($schema) . '" data-ojp-completion-schema>'
                . '</div>'
            )
            . '<div class="ojp-form-actions"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">문제 저장</button></div></form>';
    }

    private function visualGradingSelector(?ProblemMetadata $metadata): string
    {
        $options = [];
        foreach (VisualGradingMode::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $this->select(
            'visual_grading_mode',
            '이미지 채점 (Python 그림 출력)',
            ($metadata?->visualGradingMode() ?? VisualGradingMode::None)->value,
            $options
        )
            . '<p class="ojp-form-note">선택하면 특수 채점기가 필수로 켜지고 허용 언어는 Python 3 하나여야 합니다.'
            . ' 학생 코드 뒤에 그림을 PNG로 내보내는 비공개 코드가 자동으로 붙습니다.'
            . ' 저장한 뒤 테스트 데이터 화면에서 정답 이미지를 올리고 이미지 채점기를 설치해 주세요.</p>';
    }

    /**
     * @param null|array<string, mixed> $metadataDraft
     * @param null|array<string, mixed> $authoringDraft
     */
    private function draftReviews(
        ?JudgeProblem $problem,
        ?ProblemMetadata $metadata,
        ?array $metadataDraft,
        ?array $authoringDraft
    ): string {
        $content = '';
        if ($metadataDraft !== null) {
            $suggestedTopics = is_array($metadataDraft['topic_keys'] ?? null)
                ? $metadataDraft['topic_keys']
                : [];
            $content .= $this->draftPanel(
                'AI 메타데이터 초안 검토',
                '난이도와 주제를 확인한 뒤 적용할 항목을 선택하세요.',
                (string) ($metadataDraft['reference'] ?? ''),
                [
                    $this->draftField(
                        'difficulty_score',
                        '난이도',
                        $this->difficultyLabel($metadata?->difficulty()?->value()),
                        $this->difficultyLabel(
                            is_int($metadataDraft['difficulty_score'] ?? null)
                                ? $metadataDraft['difficulty_score']
                                : null
                        ),
                        (string) ($metadataDraft['difficulty_score'] ?? '')
                    ),
                    $this->draftField(
                        'topic_keys',
                        '세부 주제',
                        $this->topicLabels($metadata?->topicKeys() ?? []),
                        $this->topicLabels($suggestedTopics),
                        (string) json_encode(
                            array_values($suggestedTopics),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )
                    ),
                ],
                '<strong>AI 판단 근거</strong><p>'
                    . $this->html->escape(
                        (string) ($metadataDraft['rationale'] ?? '')
                    )
                    . '</p><p>신뢰도 '
                    . number_format(
                        (float) ($metadataDraft['confidence'] ?? 0.0) * 100,
                        0
                    )
                    . '%</p>'
            );
        }
        if ($authoringDraft !== null) {
            $fields = is_array($authoringDraft['fields'] ?? null)
                ? $authoringDraft['fields']
                : [];
            $current = $this->problemFields($problem);
            $labels = [
                'title' => '문제 제목',
                'description' => '문제 설명',
                'input' => '입력',
                'output' => '출력',
                'sample_input' => '예시 입력',
                'sample_output' => '예시 출력',
                'hint' => '힌트',
            ];
            $rows = [];
            foreach ($labels as $field => $label) {
                if (! array_key_exists($field, $fields)) {
                    continue;
                }
                $rows[] = $this->draftField(
                    $field,
                    $label,
                    $current[$field] ?? '',
                    (string) $fields[$field],
                    (string) $fields[$field],
                    $field !== 'title'
                );
            }
            $content .= $this->draftPanel(
                'AI 문제 작성 초안 검토',
                '필드별 제안을 편집 폼으로 옮긴 뒤 자유롭게 수정할 수 있습니다.',
                (string) ($authoringDraft['reference'] ?? ''),
                $rows
            );
        }

        return $content;
    }

    /**
     * @param list<string> $fields
     */
    private function draftPanel(
        string $title,
        string $description,
        string $reference,
        array $fields,
        string $note = ''
    ): string {
        return '<section class="ojp-form-section ojp-draft-review"'
            . ' data-ojp-draft-review data-draft-reference="'
            . $this->html->escape($reference) . '">'
            . '<div class="ojp-section-heading"><div><h2>'
            . $this->html->escape($title) . '</h2><p>'
            . $this->html->escape($description)
            . '</p></div><span class="ojp-status-badge ojp-status-badge--warning">'
            . '저장 전 초안</span></div>'
            . '<div class="ojp-draft-comparison">' . implode('', $fields) . '</div>'
            . ($note === '' ? '' : '<div class="ojp-draft-note">' . $note . '</div>')
            . '<div class="ojp-action-bar"><span class="ojp-form-status"'
            . ' data-ojp-draft-status aria-live="polite"></span>'
            . '<button class="ojp-button ojp-button--primary" type="button"'
            . ' data-ojp-apply-draft>선택 항목을 편집 폼에 적용</button></div>'
            . '</section>';
    }

    private function draftField(
        string $field,
        string $label,
        string $current,
        string $suggested,
        string $transferValue,
        bool $multiline = false
    ): string {
        return '<article class="ojp-draft-field">'
            . '<label class="ojp-check"><input type="checkbox" checked'
            . ' data-ojp-draft-select data-ojp-draft-target="'
            . $this->html->escape($field) . '"><span>'
            . $this->html->escape($label) . ' 적용</span></label>'
            . '<div><span class="ojp-label">현재값</span>'
            . $this->draftPreview($current, $multiline) . '</div>'
            . '<div><span class="ojp-label">AI 제안</span>'
            . $this->draftPreview($suggested, $multiline) . '</div>'
            . '<textarea hidden data-ojp-draft-value="'
            . $this->html->escape($field) . '">'
            . $this->html->escape($transferValue) . '</textarea>'
            . '</article>';
    }

    private function draftPreview(string $value, bool $multiline): string
    {
        $value = $value === '' ? '입력 없음' : $value;
        $preview = $this->truncateUtf8($value, 4000);
        if (! $multiline) {
            return '<p class="ojp-draft-value">'
                . $this->html->escape($preview) . '</p>';
        }

        return '<pre class="ojp-draft-code">' . $this->html->escape($preview)
            . '</pre>';
    }

    private function truncateUtf8(string $value, int $maximumBytes): string
    {
        if (strlen($value) <= $maximumBytes) {
            return $value;
        }
        $preview = substr($value, 0, $maximumBytes);
        while ($preview !== '' && preg_match('//u', $preview) !== 1) {
            $preview = substr($preview, 0, -1);
        }

        return $preview . "\n... 전체 내용은 적용 후 편집기에서 확인할 수 있습니다.";
    }

    /**
     * @return array<string, string>
     */
    private function problemFields(?JudgeProblem $problem): array
    {
        return [
            'title' => $problem?->title() ?? '',
            'description' => $problem?->description() ?? '',
            'input' => $problem?->input() ?? '',
            'output' => $problem?->output() ?? '',
            'sample_input' => $problem?->sampleInput() ?? '',
            'sample_output' => $problem?->sampleOutput() ?? '',
            'hint' => $problem?->hint() ?? '',
        ];
    }

    private function difficultyLabel(?int $difficulty): string
    {
        return match ($difficulty) {
            1 => '1 · 입문',
            2 => '2 · 기초',
            3 => '3 · 보통',
            4 => '4 · 어려움',
            5 => '5 · 매우 어려움',
            default => '미분석',
        };
    }

    /**
     * @param list<string> $topicKeys
     */
    private function topicLabels(array $topicKeys): string
    {
        $labels = [];
        foreach ($topicKeys as $topicKey) {
            $topic = $this->topics->find($topicKey);
            $labels[] = $topic?->label() ?? $topicKey;
        }

        return $labels === [] ? '선택 없음' : implode(', ', $labels);
    }

    private function section(string $title, string $content): string
    {
        return '<section class="ojp-form-section"><h2>'
            . $this->html->escape($title) . '</h2>' . $content . '</section>';
    }

    private function input(
        string $name,
        string $label,
        string $value,
        bool $required = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label) . '</span><input class="ojp-input"'
            . ' type="text" name="' . $this->html->escape($name) . '" value="'
            . $this->html->escape($value) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function number(
        string $name,
        string $label,
        int $value,
        int $min,
        int $max
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label) . '</span><input class="ojp-input"'
            . ' type="number" name="' . $this->html->escape($name)
            . '" value="' . $value . '" min="' . $min . '" max="' . $max
            . '" required></label>';
    }

    /**
     * @param array<string, string> $options
     */
    private function select(
        string $name,
        string $label,
        string $value,
        array $options
    ): string {
        $html = '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label) . '</span><select class="ojp-select" name="'
            . $this->html->escape($name) . '">';
        foreach ($options as $optionValue => $optionLabel) {
            $html .= '<option value="' . $this->html->escape((string) $optionValue) . '"'
                . ((string) $optionValue === $value ? ' selected' : '') . '>'
                . $this->html->escape($optionLabel) . '</option>';
        }

        return $html . '</select></label>';
    }

    private function textarea(
        string $name,
        string $label,
        string $value,
        int $rows,
        bool $required = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label) . '</span><textarea class="ojp-textarea"'
            . ' name="' . $this->html->escape($name) . '" rows="' . $rows . '"'
            . ($required ? ' required' : '') . '>'
            . $this->html->escape($value) . '</textarea></label>';
    }

    private function richTextEditor(
        string $name,
        string $label,
        string $value,
        int $rows,
        int $problemId,
        bool $required = false
    ): string {
        return (new RichTextEditor())->render(
            $name,
            $label,
            $value,
            $rows,
            $required,
            true,
            new RichTextImageUpload(
                $this->actionUrls->ajaxUrl(),
                $this->security->hiddenFields('oj_problem_upload_image')
                . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
            )
        );
    }

    private function languageSelector(?int $disabledMask): string
    {
        $enabled = array_fill_keys(
            $this->languages->enabledIds($disabledMask),
            true
        );
        $checks = '';
        foreach ($this->languages->all() as $languageId => $label) {
            $checks .= '<label class="ojp-check"><input type="checkbox"'
                . ' name="enabled_language_ids[]" value="' . $languageId . '"'
                . (isset($enabled[$languageId]) ? ' checked' : '')
                . '><span>' . $this->html->escape($label) . '</span></label>';
        }

        return '<fieldset class="ojp-fieldset"><legend>허용 언어</legend>'
            . '<p class="ojp-form-note">사이트 제출 설정에서도 허용된 언어만 실제 제출 화면에 표시됩니다.</p>'
            . '<div class="ojp-check-grid">' . $checks . '</div>'
            . '</fieldset>';
    }

    private function completionLanguageSelector(string $schema): string
    {
        $current = $this->schemaLanguage($schema);
        $currentId = null;
        $options = ['' => '언어 선택'];
        if ($current !== '') {
            try {
                $currentId = $this->languages->idForKey($current);
            } catch (\DomainException) {
                $current = '';
                $options[''] = '저장된 언어를 다시 선택해 주세요';
            }
        }
        foreach ($this->languages->keyedLanguages() as $key => $label) {
            // Preserve saved aliases so merely reopening a problem does not change its hash.
            $value = $currentId === $this->languages->idForKey($key) ? $current : $key;
            $options[$value] = $label;
        }

        return $this->select('completion_language_key', '빈칸 문제 언어', $current, $options);
    }

    /**
     * @param list<string> $selected
     */
    private function topicSelector(array $selected): string
    {
        $categories = [];
        $details = [];
        foreach ($this->topics->all() as $topic) {
            if ($topic->isCategory()) {
                $categories[$topic->key()] = $topic;
            } else {
                $details[$topic->categoryKey()][] = $topic;
            }
        }
        $options = '';
        foreach ($categories as $categoryKey => $category) {
            $options .= '<optgroup label="' . $this->html->escape($category->label()) . '">';
            foreach ($details[$categoryKey] ?? [] as $detail) {
                if (! $detail->isActive()) {
                    continue;
                }
                $options .= '<option value="' . $this->html->escape($detail->key()) . '"'
                    . (in_array($detail->key(), $selected, true) ? ' selected' : '')
                    . '>' . $this->html->escape($detail->label()) . '</option>';
            }
            $options .= '</optgroup>';
        }

        return '<label class="ojp-field"><span class="ojp-label">세부 주제(선택)</span>'
            . '<select class="ojp-select ojp-select--multiple" name="topic_keys[]"'
            . ' multiple size="8"'
            . ' data-ojp-max-selections="3">'
            . $options . '</select>'
            . '<span class="ojp-field-help" data-ojp-selection-help>해당하는 주제가 없으면 선택하지 않아도 됩니다. 최대 3개까지 선택할 수 있습니다.</span>'
            . '</label>';
    }

    private function aiAuthoringForm(
        ?JudgeProblem $problem,
        ?ProblemMetadata $metadata
    ): string {
        return '<section class="ojp-form-section">'
            . '<div class="ojp-section-heading"><div><h2>AI 문제 초안</h2>'
            . '<p>Jobs가 초안을 만들며 현재 문제에는 자동으로 반영하지 않습니다.</p>'
            . '</div></div>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->ajaxUrl())
            . '" data-ojp-ai-job-form>'
            . $this->security->hiddenFields('oj_problem_ai_generate')
            . '<input type="hidden" name="problem_id" value="'
            . ($problem?->id()->value() ?? 0) . '">'
            . '<div class="ojp-form-grid">'
            . $this->select(
                'ai_operation',
                '생성 범위',
                'full_draft',
                [
                    'title_only' => '제목만 생성',
                    'full_draft' => '문제 전체 초안',
                ]
            )
            . $this->select(
                'difficulty_score',
                '목표 난이도',
                (string) ($metadata?->difficulty()?->value() ?? ''),
                [
                    '' => 'AI가 판단',
                    '1' => '1 · 입문',
                    '2' => '2 · 기초',
                    '3' => '3 · 보통',
                    '4' => '4 · 어려움',
                    '5' => '5 · 매우 어려움',
                ]
            )
            . $this->topicSelector($metadata?->topicKeys() ?? [])
            . '<label class="ojp-field"><span class="ojp-label">학습 목표와 요청</span>'
            . '<textarea class="ojp-textarea" name="brief" rows="8" required>'
            . $this->html->escape($problem?->title() ?? '')
            . '</textarea></label></div>'
            . '<div class="ojp-form-actions"><span class="ojp-form-status"'
            . ' data-ojp-ai-job-status aria-live="polite"></span>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . 'AI 초안 작업 등록</button></div></form></section>';
    }

    private function modeOption(
        string $value,
        string $label,
        CodeEntryMode $current
    ): string {
        return '<label><input type="radio" name="code_entry_mode" value="'
            . $value . '"' . ($current->value === $value ? ' checked' : '')
            . '><span>' . $this->html->escape($label) . '</span></label>';
    }

    private function emptySchema(): string
    {
        return '{"language_key":"","blanks":[]}';
    }

    private function schemaLanguage(string $schema): string
    {
        try {
            $decoded = json_decode($schema, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        return is_array($decoded)
            ? (string) ($decoded['language_key'] ?? '')
            : '';
    }
}
