<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlPreviewService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyRow;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlIssue;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlPreview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlPreviewRow;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class ProblemXmlPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemXmlPreviewService $previews,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $topics = $this->previews->selectableTopics($actorUserId);
        } catch (DomainException $error) {
            return $this->pages->respond(
                '문제 XML',
                '<div class="ojp-error-state"><h1>접근할 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage())
                    . '</p></div>',
                'problems.xml',
                $request,
                403
            );
        }

        $preview = null;
        $result = null;
        $loadError = null;
        $draftReference = trim($request->queryString('draft'));
        $resultReference = trim($request->queryString('result'));
        try {
            if ($draftReference !== '') {
                $preview = $this->previews->preview(
                    $actorUserId,
                    $draftReference
                );
            }
            if ($resultReference !== '') {
                $result = $this->previews->result(
                    $actorUserId,
                    $resultReference
                );
            }
        } catch (DomainException $error) {
            $loadError = $error->getMessage();
        }

        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">전역 관리자 전용</p>'
            . '<h1>문제 XML</h1><p>'
            . '문제 원문과 선택한 메타데이터·테스트데이터를 검증한 뒤 가져오거나 내보냅니다.'
            . '</p></div></header>';
        if ($loadError !== null) {
            $content .= '<div class="ojp-notice ojp-notice--danger">'
                . '<strong>작업 내용을 열지 못했습니다.</strong><p>'
                . $this->html->escape($loadError) . '</p></div>';
        }
        if ($result instanceof ProblemXmlApplyResult) {
            $content .= $this->resultSection($result);
        }
        $content .= '<div class="ojp-xml-tools">'
            . $this->importForm($topics, $preview)
            . $this->exportForm()
            . '</div>';
        if ($preview instanceof ProblemXmlPreview) {
            $content .= $this->previewSection($preview);
        }

        return $this->pages->respond(
            '문제 XML',
            $content,
            'problems.xml',
            $request
        );
    }

    /**
     * @param list<ProblemTopic> $topics
     */
    private function importForm(
        array $topics,
        ?ProblemXmlPreview $preview
    ): string {
        $options = '<option value=""'
            . ($preview === null || $preview->fallbackTopicKey() === '' ? ' selected' : '')
            . '>주제 지정 안 함</option>';
        foreach ($topics as $topic) {
            $selected = $preview?->fallbackTopicKey() === $topic->key()
                ? ' selected'
                : '';
            $options .= '<option value="'
                . $this->html->escape($topic->key()) . '"'
                . $selected . '>'
                . $this->html->escape($topic->label())
                . '</option>';
        }

        return '<section class="ojp-section ojp-xml-tool">'
            . '<header class="ojp-section-heading"><div><h2>XML 가져오기</h2>'
            . '<p>파일을 먼저 분석하고 적용할 문제만 선택합니다.</p></div></header>'
            . '<form class="ojp-form-stack" method="post" enctype="multipart/form-data"'
            . ' action="' . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_xml_import')
            . '<input type="hidden" name="operation" value="preview">'
            . '<label class="ojp-field"><span class="ojp-label">XML 파일</span>'
            . '<input class="ojp-input" type="file" name="problem_xml_file"'
            . ' accept=".xml,text/xml,application/xml" required>'
            . '<span class="ojp-field-help">UTF-8 XML, 최대 20MB, 문제 최대 200개</span>'
            . '</label><label class="ojp-field"><span class="ojp-label">'
            . '메타데이터가 없을 때 사용할 주제(선택)</span>'
            . '<select class="ojp-select" name="fallback_topic_key">'
            . $options . '</select>'
            . '<span class="ojp-field-help">선택하지 않으면 가져온 문제를 주제 미지정으로 저장합니다.</span>'
            . '</label>'
            . '<fieldset class="ojp-fieldset"><legend>가져오기 범위</legend>'
            . $this->check(
                'include_metadata',
                '메타데이터 가져오기',
                '난이도·주제·코드 입력 방식을 적용합니다.',
                true
            )
            . $this->check(
                'include_test_data',
                '테스트데이터 가져오기',
                'XML에 포함된 입력·출력 쌍만 추가하거나 바꿉니다.',
                true
            )
            . $this->check(
                'allow_overwrite',
                '기존 문제 덮어쓰기 허용',
                '같은 번호가 있는 항목은 현재 상태가 바뀌지 않았을 때만 적용합니다.',
                false
            )
            . '</fieldset><div class="ojp-notice ojp-notice--info"><strong>'
            . '메타데이터를 제외하는 경우</strong><p>'
            . '선택한 기본 주제 또는 주제 미지정, 난이도 미분석, 전체 코드 작성 방식으로 저장됩니다.'
            . '</p></div><button class="ojp-button ojp-button--primary" type="submit"'
            . '>분석 및 미리보기</button></form></section>';
    }

    private function exportForm(): string
    {
        return '<section class="ojp-section ojp-xml-tool">'
            . '<header class="ojp-section-heading"><div><h2>XML 내보내기</h2>'
            . '<p>문제 번호 또는 범위를 입력해 파일로 내려받습니다.</p></div></header>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_xml_export')
            . '<label class="ojp-field"><span class="ojp-label">문제 번호</span>'
            . '<textarea class="ojp-textarea" name="problem_selection" rows="6"'
            . ' placeholder="1000-1010, 1020, 1025" required></textarea>'
            . '<span class="ojp-field-help">쉼표·공백 또는 시작-끝 범위, 최대 200개</span>'
            . '</label><fieldset class="ojp-fieldset"><legend>내보내기 범위</legend>'
            . $this->check(
                'include_metadata',
                '메타데이터 포함',
                '난이도·주제·코드 입력 방식을 OJ 확장 요소로 넣습니다.',
                true
            )
            . $this->check(
                'include_test_data',
                '테스트데이터 포함',
                '문제당 최대 20쌍, 파일당 8MB, 전체 16MB까지 포함합니다.',
                true
            )
            . '</fieldset><button class="ojp-button ojp-button--primary" type="submit">'
            . 'XML 내려받기</button></form></section>';
    }

    private function previewSection(ProblemXmlPreview $preview): string
    {
        $issueRows = '';
        foreach ($preview->issues() as $issue) {
            $issueRows .= $this->issueRow($issue);
        }
        $rows = '';
        foreach ($preview->rows() as $row) {
            $problemId = $row->targetProblemId()?->value();
            $operation = $row->operation() === ProblemXmlPreviewRow::CREATE
                ? '새 문제'
                : '기존 문제 수정';
            $status = $row->blocked()
                ? '<span class="ojp-status-badge ojp-status-badge--danger">적용 불가</span>'
                : '<span class="ojp-status-badge ojp-status-badge--success">적용 가능</span>';
            $rows .= '<tr><td><input type="checkbox" name="selected_indexes[]" value="'
                . $row->index() . '" aria-label="'
                . $this->html->escape($row->title()) . ' 선택"'
                . ($row->blocked() ? ' disabled' : ' checked') . '></td><td>'
                . ($problemId === null ? '자동' : $problemId) . '</td><td><strong>'
                . $this->html->escape($row->title()) . '</strong></td><td>'
                . $operation . '</td><td>' . $row->testPairCount()
                . '쌍</td><td>' . $status
                . ($row->message() === ''
                    ? ''
                    : '<p class="ojp-table-note">'
                        . $this->html->escape($row->message()) . '</p>')
                . '</td></tr>';
        }

        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>가져오기 미리보기</h2><p>'
            . '적용 버튼을 누를 때 문제와 테스트 파일 상태를 다시 검사합니다.'
            . '</p></div><span class="ojp-status-badge ojp-status-badge--warning">'
            . $preview->selectableCount() . '개 적용 가능</span></header>'
            . ($issueRows === ''
                ? ''
                : '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
                    . '<th>XML 항목</th><th>문제 번호</th><th>제목</th><th>분석 오류</th>'
                    . '</tr></thead><tbody>' . $issueRows . '</tbody></table></div>')
            . '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_xml_import')
            . '<input type="hidden" name="operation" value="apply">'
            . '<input type="hidden" name="draft_reference" value="'
            . $this->html->escape($preview->reference()) . '">'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>선택</th><th>문제 번호</th><th>제목</th><th>작업</th>'
            . '<th>테스트</th><th>검증</th></tr></thead><tbody>'
            . ($rows === ''
                ? '<tr><td colspan="6" class="ojp-empty-cell">적용할 수 있는 항목이 없습니다.</td></tr>'
                : $rows)
            . '</tbody></table></div><div class="ojp-xml-apply-bar">'
            . '<p>미리보기는 1시간 뒤 자동 폐기됩니다.</p>'
            . '<button class="ojp-button ojp-button--primary" type="submit"'
            . ($preview->selectableCount() < 1 ? ' disabled' : '')
            . '>선택한 문제 적용</button></div></form></section>';
    }

    private function resultSection(ProblemXmlApplyResult $result): string
    {
        $rows = '';
        foreach ($result->rows() as $row) {
            $label = match ($row->status()) {
                ProblemXmlApplyRow::CREATED => '생성 완료',
                ProblemXmlApplyRow::UPDATED => '수정 완료',
                default => '실패',
            };
            $tone = $row->status() === ProblemXmlApplyRow::FAILED
                ? 'danger'
                : 'success';
            $rows .= '<tr><td>' . ($row->index() + 1) . '</td><td>'
                . ($row->problemId()?->value() ?? '자동') . '</td><td>'
                . $this->html->escape($row->title()) . '</td><td>'
                . '<span class="ojp-status-badge ojp-status-badge--'
                . $tone . '">' . $label . '</span>'
                . ($row->message() === ''
                    ? ''
                    : '<p class="ojp-table-note">'
                        . $this->html->escape($row->message()) . '</p>')
                . '</td></tr>';
        }

        return '<section class="ojp-section"><header class="ojp-section-heading"><div>'
            . '<h2>최근 가져오기 결과</h2><p>'
            . '생성 ' . $result->createdCount() . '개, 수정 '
            . $result->updatedCount() . '개, 실패 '
            . $result->failedCount() . '개</p></div></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>XML 항목</th><th>문제 번호</th><th>제목</th><th>결과</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function issueRow(ProblemXmlIssue $issue): string
    {
        return '<tr><td>' . ($issue->index() + 1) . '</td><td>'
            . ($issue->problemId() ?? '미지정') . '</td><td>'
            . $this->html->escape(
                $issue->title() === '' ? '제목 없음' : $issue->title()
            )
            . '</td><td>' . $this->html->escape($issue->message()) . '</td></tr>';
    }

    private function check(
        string $name,
        string $label,
        string $description,
        bool $checked
    ): string {
        return '<label class="ojp-check"><input type="checkbox" name="'
            . $this->html->escape($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '><span><strong>'
            . $this->html->escape($label) . '</strong><small>'
            . $this->html->escape($description) . '</small></span></label>';
    }
}
