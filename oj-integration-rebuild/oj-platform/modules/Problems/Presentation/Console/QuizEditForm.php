<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;

final class QuizEditForm
{
    public function __construct(
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html,
        private readonly QuizKeyFactory $keys
    ) {
    }

    /**
     * @param list<QuizBankQuestion> $bankItems
     */
    public function render(
        JudgeProblem $problem,
        ?QuizRevision $draft,
        ?QuizRevision $published,
        bool $canRegradeCompleted,
        string $actionKey,
        array $bankItems = []
    ): string {
        $definition = $draft?->definition();
        $questions = $definition?->questions() ?? [null];
        $questionHtml = '';
        foreach ($questions as $index => $question) {
            $questionHtml .= $this->question($question, $index, true);
        }

        return '<form class="ojp-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-oj-quiz-editor>'
            . $this->security->hiddenFields($actionKey)
            . '<input type="hidden" name="problem_id" value="'
            . $problem->id()->value() . '">'
            . '<section class="ojp-form-section"><div class="ojp-section-heading">'
            . '<div><h2>기본 설정</h2><p>문제 ' . $problem->id()->value()
            . ' · ' . $this->html->escape($problem->title()) . '</p></div>'
            . ($draft === null
                ? '<span class="ojp-status-badge ojp-status-badge--neutral">새 초안</span>'
                : '<span class="ojp-status-badge ojp-status-badge--warning">초안 저장됨</span>')
            . '</div><div class="ojp-form-grid">'
            . $this->textField(
                'title',
                '퀴즈 제목',
                $definition?->title() ?? '',
                '문제의 핵심 개념 확인',
                true
            )
            . $this->numberField(
                'attempt_limit',
                '응시 횟수',
                $definition?->attemptLimit() ?? 1,
                1,
                20
            )
            . '<label class="ojp-field"><span class="ojp-label">결과 공개</span>'
            . '<select class="ojp-select" name="result_policy" data-oj-quiz-result-policy>'
            . $this->policyOptions(
                $definition?->resultPolicy() ?? QuizResultPolicy::Full
            )
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">결과 공개 시각</span>'
            . '<input class="ojp-input" type="datetime-local"'
            . ' name="results_available_at" value="'
            . $this->html->escape($this->dateTimeLocal(
                $definition?->resultsAvailableAt()
            ))
            . '" data-oj-quiz-results-at></label>'
            . '<label class="ojp-field ojp-field--wide"><span class="ojp-label">설명</span>'
            . '<textarea class="ojp-textarea" name="description" rows="4"'
            . ' placeholder="응시 전에 알아야 할 내용을 입력합니다.">'
            . $this->html->escape($definition?->description() ?? '')
            . '</textarea></label></div>'
            . '<div class="ojp-toggle-list">'
            . $this->toggle(
                'enabled',
                '학습자에게 퀴즈 제공',
                $definition?->isEnabled() ?? true
            )
            . $this->toggle(
                'public_statistics',
                '학습자에게 익명 집계 통계 공개',
                $definition?->publicStatistics() ?? false
            )
            . $this->toggle(
                'protect_published',
                '게시 버전 보호',
                $definition?->protectPublished() ?? true
            )
            . '</div></section>'
            . $this->reapplyOptions(
                $published,
                $definition?->protectPublished() ?? true,
                $canRegradeCompleted
            )
            . '<section class="ojp-form-section"><div class="ojp-section-heading">'
            . '<div><h2>문항</h2><p>선택형 문항은 선택지 2~8개가 필요하며,'
            . ' 단답형 문항은 정답을 1개 이상 등록해야 합니다.</p></div>'
            . '<button class="ojp-button" type="button" data-oj-quiz-add-question>'
            . '문항 추가</button></div>'
            . $this->bankImportPicker($problem->id()->value(), $bankItems)
            . '<div class="ojp-quiz-questions" data-oj-quiz-questions>'
            . $questionHtml . '</div></section>'
            . '<form id="ojp-quiz-export-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '" hidden>'
            . $this->security->hiddenFields('oj_quiz_export_to_bank')
            . '<input type="hidden" name="problem_id" value="'
            . $problem->id()->value() . '"></form>'
            . '<div class="ojp-sticky-actions"><a class="ojp-button" href="'
            . ($draft === null
                ? '/oj-console/quizzes'
                : '/quizzes/' . $problem->id()->value())
            . '">취소</a>'
            . '<button class="ojp-button ojp-button--primary" type="submit"'
            . ' name="intent" value="save_draft">'
            . ($draft === null ? '초안 만들기' : '초안 저장')
            . '</button>'
            . ($draft === null
                ? ''
                : '<button class="ojp-button ojp-button--success" type="submit"'
                    . ' name="intent" value="publish">새 버전 게시</button>')
            . '</div></form>';
    }

    /**
     * @param list<QuizBankQuestion> $bankItems
     */
    private function bankImportPicker(int $problemId, array $bankItems): string
    {
        if ($bankItems === []) {
            return '<div class="ojp-notice ojp-notice--info"><strong>문항 은행이 비어 있습니다.</strong>'
                . '<p>문항 은행에 문항을 등록하면 이 화면에서 바로 가져와 쓸 수 있습니다. '
                . '<a href="/oj-console/quiz-bank/new">문항 은행에 문항 추가</a></p></div>';
        }
        $options = '<option value="">문항을 선택하세요</option>';
        foreach ($bankItems as $item) {
            $options .= '<option value="' . $item->id() . '">'
                . $this->html->escape($item->question()->title()) . '</option>';
        }

        return '<form class="ojp-filter-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_quiz_import_bank_question')
            . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
            . '<label class="ojp-field ojp-filter-bar__primary"><span class="ojp-label">'
            . '문항 은행에서 가져오기</span><select class="ojp-select" name="bank_id" required>'
            . $options . '</select></label>'
            . '<button class="ojp-button" type="submit">가져오기</button></form>';
    }

    public function renderBankItem(?QuizQuestion $item, string $actionKey, ?int $bankId): string
    {
        return '<form class="ojp-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-oj-quiz-editor>'
            . $this->security->hiddenFields($actionKey)
            . ($bankId === null
                ? ''
                : '<input type="hidden" name="bank_id" value="' . $bankId . '">')
            . '<section class="ojp-form-section"><div class="ojp-section-heading">'
            . '<div><h2>문항 은행 항목</h2><p>이 문항은 여러 퀴즈에서 불러와 '
            . '재사용할 수 있습니다.</p></div></div>'
            . '<div class="ojp-quiz-questions" data-oj-quiz-questions>'
            . $this->question($item, 0, false) . '</div>'
            . '<button class="ojp-button" type="button" data-oj-quiz-add-question hidden>'
            . '문항 추가</button></section>'
            . '<div class="ojp-sticky-actions"><a class="ojp-button"'
            . ' href="/oj-console/quiz-bank">취소</a>'
            . '<button class="ojp-button ojp-button--primary" type="submit">저장</button>'
            . '</div></form>';
    }

    private function question(
        ?QuizQuestion $question,
        int $questionIndex,
        bool $showExportButton
    ): string {
        $questionKey = $question?->key() ?? $this->keys->uuid();
        $type = $question?->type() ?? 'single_choice';
        $isShortAnswer = $type === 'short_answer';
        $choices = $question?->choices() ?? [null, null];
        $choiceHtml = '';
        foreach ($choices as $choiceIndex => $choice) {
            $choiceHtml .= $this->choice(
                $choice,
                $questionIndex,
                $choiceIndex,
                $choice?->isCorrect() ?? $choiceIndex === 0,
                $type
            );
        }
        $acceptedAnswersText = implode("\n", $question?->acceptedAnswers() ?? []);
        $exportButton = $showExportButton && $question !== null
            ? '<button class="ojp-button ojp-button--small" type="submit"'
                . ' form="ojp-quiz-export-form" name="question_key" value="'
                . $this->html->escape($question->key())
                . '" data-oj-quiz-export-button>은행에 저장</button>'
            : '';

        return '<article class="ojp-quiz-question" data-oj-quiz-question>'
            . '<div class="ojp-section-heading"><div><p class="ojp-eyebrow">'
            . '문항 <span data-oj-quiz-question-number>'
            . ($questionIndex + 1) . '</span></p><h3>'
            . $this->html->escape($this->typeLabel($type)) . '</h3></div>'
            . '<div class="ojp-command-group">' . $exportButton
            . '<button class="ojp-icon-button" type="button"'
            . ' aria-label="문항 제거" title="문항 제거" data-oj-quiz-remove-question>'
            . '×</button></div></div>'
            . '<input type="hidden" value="' . $this->html->escape($questionKey)
            . '" data-oj-quiz-question-field="question_key">'
            . '<input type="hidden" value="1" data-oj-quiz-question-field="enabled">'
            . '<div class="ojp-form-grid">'
            . $this->questionTypeField($type)
            . $this->questionTextField(
                'title',
                '문항 제목',
                $question?->title() ?? '',
                '예: 구간 합의 의미',
                true
            )
            . $this->questionNumberField(
                'points',
                '배점',
                $question?->points() ?? 1
            )
            . $this->questionTextArea(
                'body',
                '질문',
                $question?->body() ?? '',
                '학습자에게 보여 줄 질문을 입력합니다.',
                true
            )
            . $this->questionTextArea(
                'explanation',
                '정답 해설',
                $question?->explanation() ?? '',
                '정답 공개 정책이 허용할 때 표시됩니다.',
                false
            )
            . '</div><div class="ojp-section-heading ojp-section-heading--compact"'
            . ' data-oj-quiz-choices-section' . ($isShortAnswer ? ' hidden' : '') . '>'
            . '<div><h4>선택지</h4><p>왼쪽 표시로 정답을 지정합니다'
            . '(다중 답은 여러 개 선택 가능).</p></div>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-oj-quiz-add-choice>선택지 추가</button></div>'
            . '<div class="ojp-quiz-choices" data-oj-quiz-choices'
            . ($isShortAnswer ? ' hidden' : '') . '>'
            . $choiceHtml . '</div>'
            . '<div class="ojp-form-grid" data-oj-quiz-accepted-answers-section'
            . ($isShortAnswer ? '' : ' hidden') . '>'
            . '<label class="ojp-field ojp-field--wide"><span class="ojp-label">'
            . '정답으로 인정할 답안(한 줄에 하나씩)</span>'
            . '<textarea class="ojp-textarea" rows="3" placeholder="예: 4&#10;사"'
            . ' data-oj-quiz-question-field="accepted_answers_text">'
            . $this->html->escape($acceptedAnswersText) . '</textarea></label>'
            . '</div></article>';
    }

    private function questionTypeField(string $type): string
    {
        $options = [
            'single_choice' => '단일 선택',
            'multi_choice' => '다중 답',
            'short_answer' => '단답형',
        ];
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $value . '"'
                . ($type === $value ? ' selected' : '') . '>'
                . $this->html->escape($label) . '</option>';
        }

        return '<label class="ojp-field"><span class="ojp-label">문항 유형</span>'
            . '<select class="ojp-select" data-oj-quiz-question-field="type"'
            . ' data-oj-quiz-question-type>' . $html . '</select></label>';
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'multi_choice' => '다중 답',
            'short_answer' => '단답형',
            default => '단일 선택',
        };
    }

    private function choice(
        ?QuizChoice $choice,
        int $questionIndex,
        int $choiceIndex,
        bool $correct,
        string $type
    ): string {
        $choiceKey = $choice?->key() ?? $this->keys->uuid();
        $isMulti = $type === 'multi_choice';

        return '<div class="ojp-quiz-choice" data-oj-quiz-choice>'
            . '<label class="ojp-quiz-choice__correct">'
            . '<input type="' . ($isMulti ? 'checkbox' : 'radio')
            . '" name="questions[' . $questionIndex . '][' . ($isMulti
                ? 'correct_choices][]'
                : 'correct_choice]')
            . '" value="' . $this->html->escape($choiceKey)
            . '"' . ($correct ? ' checked' : '')
            . ' data-oj-quiz-correct><span>정답</span></label>'
            . '<input type="hidden" value="' . $this->html->escape($choiceKey)
            . '" data-oj-quiz-choice-field="choice_key">'
            . '<input type="hidden" value="1" data-oj-quiz-choice-field="enabled">'
            . '<label class="ojp-field ojp-quiz-choice__label"><span class="ojp-label">'
            . '표시</span><input class="ojp-input" type="text" maxlength="50"'
            . ' value="' . $this->html->escape(
                $choice?->label() ?? chr(65 + $choiceIndex)
            )
            . '" data-oj-quiz-choice-field="label" required></label>'
            . '<label class="ojp-field ojp-quiz-choice__body"><span class="ojp-label">'
            . '내용</span><input class="ojp-input" type="text" value="'
            . $this->html->escape($choice?->body() ?? '')
            . '" data-oj-quiz-choice-field="body" required></label>'
            . '<button class="ojp-icon-button" type="button"'
            . ' aria-label="선택지 제거" title="선택지 제거"'
            . ' data-oj-quiz-remove-choice>×</button></div>';
    }

    private function textField(
        string $name,
        string $label,
        string $value,
        string $placeholder,
        bool $wide
    ): string {
        return '<label class="ojp-field' . ($wide ? ' ojp-field--wide' : '')
            . '"><span class="ojp-label">' . $this->html->escape($label)
            . '</span><input class="ojp-input" type="text" name="'
            . $this->html->escape($name) . '" value="'
            . $this->html->escape($value) . '" placeholder="'
            . $this->html->escape($placeholder) . '" required></label>';
    }

    private function numberField(
        string $name,
        string $label,
        int $value,
        int $min,
        int $max
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label)
            . '</span><input class="ojp-input" type="number" name="'
            . $this->html->escape($name) . '" value="' . $value
            . '" min="' . $min . '" max="' . $max . '" required></label>';
    }

    private function questionTextField(
        string $field,
        string $label,
        string $value,
        string $placeholder,
        bool $wide
    ): string {
        return '<label class="ojp-field' . ($wide ? ' ojp-field--wide' : '')
            . '"><span class="ojp-label">' . $this->html->escape($label)
            . '</span><input class="ojp-input" type="text" value="'
            . $this->html->escape($value) . '" placeholder="'
            . $this->html->escape($placeholder) . '"'
            . ' data-oj-quiz-question-field="' . $this->html->escape($field)
            . '" required></label>';
    }

    private function questionNumberField(
        string $field,
        string $label,
        float $value
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label)
            . '</span><input class="ojp-input" type="number" min="0.01"'
            . ' max="10000" step="0.01" value="'
            . $this->html->escape($this->score($value))
            . '" data-oj-quiz-question-field="' . $this->html->escape($field)
            . '" required></label>';
    }

    private function questionTextArea(
        string $field,
        string $label,
        string $value,
        string $placeholder,
        bool $required
    ): string {
        return '<label class="ojp-field ojp-field--wide"><span class="ojp-label">'
            . $this->html->escape($label)
            . '</span><textarea class="ojp-textarea" rows="3" placeholder="'
            . $this->html->escape($placeholder) . '"'
            . ' data-oj-quiz-question-field="' . $this->html->escape($field)
            . '"' . ($required ? ' required' : '') . '>'
            . $this->html->escape($value) . '</textarea></label>';
    }

    private function toggle(string $name, string $label, bool $checked): string
    {
        return '<label class="ojp-toggle-row"><input type="hidden" name="'
            . $this->html->escape($name) . '" value="0">'
            . '<input type="checkbox" name="' . $this->html->escape($name)
            . '" value="1"' . ($checked ? ' checked' : '')
            . ($name === 'protect_published'
                ? ' data-oj-quiz-protection'
                : '')
            . '>'
            . '<span>' . $this->html->escape($label) . '</span></label>';
    }

    private function reapplyOptions(
        ?QuizRevision $published,
        bool $protected,
        bool $canRegradeCompleted
    ): string {
        if ($published === null) {
            return '';
        }

        return '<section class="ojp-form-section" data-oj-quiz-reapply>'
            . '<div class="ojp-section-heading"><div><h2>기존 응시 재적용</h2><p>'
            . '현재 게시 v' . $published->revisionNumber()
            . '의 원본 응시와 결과는 그대로 보존합니다.</p></div></div>'
            . '<div class="ojp-notice ojp-notice--warning"><strong>'
            . '새 버전을 먼저 게시한 뒤 Jobs가 실행됩니다.</strong><p>'
            . '등록 당시 대상을 고정하고 실행 직전에 권한과 안정 키를 다시 검사합니다.'
            . '</p></div><div class="ojp-quiz-reapply-options"'
            . ' data-oj-quiz-reapply-options'
            . ($protected ? ' hidden' : '') . '>'
            . '<label class="ojp-check-card"><input type="checkbox"'
            . ' name="reapply_in_progress" value="1" checked'
            . ' data-oj-quiz-reapply-scope><span><strong>'
            . '진행 중 응시 다시 시작</strong><small>'
            . '원본을 교체됨으로 보존하고 응시 횟수 차감 없는 새 시도를 만듭니다.'
            . '</small></span></label>'
            . '<label class="ojp-check-card"><input type="checkbox"'
            . ' name="reapply_graded" value="1"'
            . ' data-oj-quiz-reapply-scope'
            . ($canRegradeCompleted
                ? ''
                : ' disabled data-oj-quiz-permission-disabled')
            . '><span><strong>완료 결과 재채점</strong><small>'
            . ($canRegradeCompleted
                ? '안정 키가 모두 대응하는 결과만 새 재채점 기록으로 보존합니다.'
                : '전역 퀴즈 관리자만 선택할 수 있습니다.')
            . '</small></span></label></div></section>';
    }

    private function policyOptions(QuizResultPolicy $selected): string
    {
        $options = [
            QuizResultPolicy::Full->value => '점수·정오·해설',
            QuizResultPolicy::ScoreOnly->value => '점수만',
            QuizResultPolicy::AfterEnd->value => '지정 시각 후 정오·해설',
        ];
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $value . '"'
                . ($selected->value === $value ? ' selected' : '')
                . '>' . $label . '</option>';
        }

        return $html;
    }

    private function dateTimeLocal(?string $value): string
    {
        return $value === null ? '' : str_replace(' ', 'T', substr($value, 0, 16));
    }

    private function score(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
