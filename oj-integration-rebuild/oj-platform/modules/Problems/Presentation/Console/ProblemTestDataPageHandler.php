<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataService;
use CodingPanda\OjPlatform\Modules\Problems\Application\VisualCaptureState;
use CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataInspection;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;
use Throwable;

final class ProblemTestDataPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemTestDataService $testData,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html,
        private readonly ErrorReporter $errors,
        private readonly ?VisualGradingService $visualGrading = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        try {
            $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
            $userId = $this->accounts->current()->identity()?->userId() ?? 0;
            $problem = $this->catalog->findVisible($userId, $problemId);
            $files = $this->testData->files($userId, $problemId);
            if ($problem === null) {
                throw new DomainException('문제를 찾을 수 없습니다.');
            }
            $download = trim($request->queryString('download'));
            if ($download !== '') {
                return $this->download($userId, $problemId, $download);
            }
            $inspection = ProblemTestDataInspection::inspect($files);
            $testcaseDraft = null;
            $draftError = null;
            $draftReference = trim($request->queryString('testcase_draft'));
            if ($draftReference !== '') {
                try {
                    $testcaseDraft = $this->drafts->testcases(
                        $draftReference,
                        $userId,
                        $problemId
                    );
                } catch (DomainException $error) {
                    $draftError = $error->getMessage();
                }
            }
            $selected = $this->selectedFile($files, $request->queryString('file'));
            $newFile = $request->queryString('new') === '1';
            $archiveName = trim($request->queryString('archive'));
            $archive = null;
            $archiveError = null;
            if ($archiveName !== '') {
                try {
                    $archive = $this->testData->archiveInspection(
                        $userId,
                        $problemId,
                        $archiveName
                    );
                } catch (DomainException $error) {
                    $archiveError = $error->getMessage();
                }
            }
            $tabs = $this->tabs($problem, $problemId, $userId, $files);
            $requestedTab = trim($request->queryString('tab'));
            if ($requestedTab === '' && $draftReference !== '') {
                $requestedTab = 'ai';
            }
            $activeTab = isset($tabs[$requestedTab]) ? $requestedTab : 'files';
            $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제 '
                . $problemId->value() . '</p><h1>테스트데이터 편집기</h1><p>'
                . $this->html->escape($problem->title())
                . '의 채점 파일만 관리합니다.</p></div>'
                . '<div class="ojp-command-group"><a class="ojp-button" href="/oj-console/problems/'
                . $problemId->value() . '/edit">문제 수정</a></div></header>'
                . $this->rootStrip($userId, $problemId)
                . $this->inspectionNotice($inspection)
                . $this->tabNavigation($problemId, $tabs, $activeTab)
                . '<div class="ojp-test-data-tab" data-tab="' . $activeTab . '">';
            if ($activeTab === 'files') {
                $content .= '<div class="ojp-file-workbench">'
                    . '<section class="ojp-stack">'
                    . $this->fileCommands($problemId)
                    . $this->fileTable($problemId, $files, $inspection)
                    . '</section>'
                    . '<div class="ojp-file-side">'
                    . $this->editor($problemId, $selected, $newFile, $userId)
                    . '</div>'
                    . '</div>';
                if ($archive instanceof ProblemArchiveInspection) {
                    $content .= $this->archivePreview($problemId, $archive);
                } elseif ($archiveError !== null) {
                    $content .= '<div class="ojp-notice ojp-notice--warning"><strong>'
                        . 'ZIP 내용을 확인하지 못했습니다.</strong><p>'
                        . $this->html->escape($archiveError)
                        . '</p></div>';
                }
            } elseif ($activeTab === 'visual') {
                $content .= $this->visualGradingPanel($problemId, $userId, $files);
            } elseif ($activeTab === 'spj') {
                $content .= $this->spjPanel($problemId, $problem, $files);
            } else {
                $content .= ($draftError === null
                        ? ''
                        : '<div class="ojp-notice ojp-notice--warning"><strong>'
                            . 'AI 테스트케이스 초안을 열지 못했습니다.</strong><p>'
                            . $this->html->escape($draftError) . '</p></div>')
                    . ($testcaseDraft === null
                        ? ''
                        : $this->testcaseDraftReview($problemId, $testcaseDraft, $files))
                    . $this->aiTestcasePanel($problemId, $userId);
            }
            $content .= '</div>';

            return $this->pages->respond(
                '테스트데이터 편집기',
                $content,
                'problems.index',
                $request
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '테스트데이터 편집기',
                '<div class="ojp-error-state"><h1>테스트데이터를 열 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage())
                    . '</p><a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.index',
                $request,
                404
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->pages->respond(
                '테스트데이터 편집기',
                '<div class="ojp-error-state"><h1>테스트데이터를 읽지 못했습니다.</h1>'
                    . '<p>데이터 루트 설정과 파일 권한을 확인해 주세요.</p>'
                    . '<a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.index',
                $request,
                500
            );
        }
    }

    /**
     * @param list<ProblemDataFile> $files
     * @return array<string, string>
     */
    private function tabs(
        JudgeProblem $problem,
        ProblemId $problemId,
        int $userId,
        array $files
    ): array {
        $tabs = ['files' => '파일 ' . count($files)];
        if ($this->visualGrading !== null && $this->visualGrading->mode($problemId)->isEnabled()) {
            $ready = false;
            foreach ($files as $file) {
                $ready = $ready || $file->name() === 'spj';
            }
            $tabs['visual'] = '이미지 채점' . ($ready ? '' : ' · 설정 필요');
        }
        if ($problem->specialJudge()) {
            $tabs['spj'] = '특수 채점기';
        }
        if ($this->access->canRunTestcaseAi($userId, $problemId)) {
            $tabs['ai'] = 'AI 초안';
        }

        return $tabs;
    }

    /**
     * @param array<string, string> $tabs
     */
    private function tabNavigation(ProblemId $problemId, array $tabs, string $active): string
    {
        $html = '<nav class="ojp-tabs ojp-test-data-tabs" aria-label="테스트데이터 메뉴">';
        foreach ($tabs as $key => $label) {
            $html .= '<a class="ojp-tab' . ($key === $active ? ' is-active' : '')
                . '" href="/oj-console/problems/' . $problemId->value()
                . '/test-data?tab=' . $key . '"'
                . ($key === $active ? ' aria-current="page"' : '') . '>'
                . $this->html->escape($label) . '</a>';
        }

        return $html . '</nav>';
    }

    private function download(
        int $userId,
        ProblemId $problemId,
        string $fileName
    ): HttpResponse {
        $contents = $this->testData->download($userId, $problemId, $fileName);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $contentType = $extension === 'zip'
            ? 'application/zip'
            : (in_array($extension, ['in', 'out', 'ans', 'txt', 'c', 'cc', 'cpp', 'log'], true)
                ? 'text/plain; charset=UTF-8'
                : 'application/octet-stream');

        return new HttpResponse(
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Content-Length' => (string) strlen($contents),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $contents
        );
    }

    private function rootStrip(int $userId, ProblemId $problemId): string
    {
        return '<div class="ojp-data-root-strip"><span>현재 문제 폴더</span><strong>'
            . $this->html->escape($this->testData->directory($userId, $problemId))
            . '</strong><span class="ojp-status-badge ojp-status-badge--success">'
            . '문제 폴더로 제한</span></div>';
    }

    private function inspectionNotice(ProblemTestDataInspection $inspection): string
    {
        if ($inspection->isValid()) {
            return '<div class="ojp-notice ojp-notice--success"><strong>파일 검사 통과</strong>'
                . '<p>입출력 쌍 ' . $inspection->pairCount()
                . '개가 연결되어 있고 빈 파일이 없습니다.</p></div>';
        }
        $issues = array_merge(
            array_map(
                static fn (string $name): string => $name . '의 입력 파일 없음',
                $inspection->missingInputs()
            ),
            array_map(
                static fn (string $name): string => $name . '의 출력 파일 없음',
                $inspection->missingOutputs()
            ),
            array_map(
                static fn (string $name): string => $name . '의 출력 파일 중복',
                $inspection->duplicateOutputs()
            ),
            array_map(
                static fn (string $name): string => $name . '이 비어 있음',
                $inspection->emptyFiles()
            )
        );

        return '<div class="ojp-notice ojp-notice--warning"><strong>파일 확인 필요</strong><p>'
            . $this->html->escape(implode(', ', $issues)) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<ProblemDataFile> $currentFiles
     */
    private function testcaseDraftReview(
        ProblemId $problemId,
        array $draft,
        array $currentFiles
    ): string {
        $current = [];
        foreach ($currentFiles as $file) {
            $current[$file->name()] = $file;
        }
        $rows = '';
        $collisions = 0;
        foreach ($draft['files'] ?? [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            $name = is_string($file['name'] ?? null) ? $file['name'] : '';
            $contents = is_string($file['contents'] ?? null)
                ? $file['contents']
                : '';
            $bytes = is_int($file['bytes'] ?? null)
                ? $file['bytes']
                : strlen($contents);
            $sha256 = is_string($file['sha256'] ?? null)
                ? $file['sha256']
                : hash('sha256', $contents);
            $hasCollision = isset($current[$name]);
            if ($hasCollision) {
                ++$collisions;
            }
            $escapedName = $this->html->escape($name);
            $rows .= '<tr><td><input type="checkbox" name="file_names[]" value="'
                . $escapedName . '" checked data-ojp-select-item aria-label="'
                . $escapedName . ' 선택"></td><td><strong>' . $escapedName
                . '</strong><span class="ojp-table-subvalue"><code>'
                . $this->html->escape($sha256) . '</code></span></td><td>'
                . $this->formatBytes($bytes) . '</td><td>'
                . ($hasCollision
                    ? '<span class="ojp-status-badge ojp-status-badge--warning">'
                        . '기존 파일 있음</span>'
                    : '<span class="ojp-status-badge ojp-status-badge--success">'
                        . '새 파일</span>')
                . '</td><td><details class="ojp-draft-file-preview"><summary>'
                . '내용 보기</summary><pre>'
                . $this->html->escape($this->previewText($contents, 8000))
                . '</pre></details></td></tr>';
        }
        $reference = $this->html->escape(
            (string) ($draft['reference'] ?? '')
        );
        $rationale = $this->html->escape(
            (string) ($draft['rationale'] ?? '')
        );

        return '<section class="ojp-section ojp-testcase-draft-review">'
            . '<header class="ojp-section-heading"><div><p class="ojp-eyebrow">'
            . 'AI 테스트케이스</p><h2>생성 초안 검토</h2><p>'
            . '선택한 파일만 현재 상태를 다시 확인한 뒤 한 번에 적용합니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--warning">'
            . '아직 미적용</span></header>'
            . '<div class="ojp-draft-note"><strong>생성 근거</strong><p>'
            . $rationale . '</p></div>'
            . '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-ojp-bulk-select>'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="apply_ai_draft">'
            . '<input type="hidden" name="problem_id" value="'
            . $problemId->value() . '">'
            . '<input type="hidden" name="draft_reference" value="'
            . $reference . '">'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th><input type="checkbox" checked data-ojp-select-all'
            . ' aria-label="전체 선택"></th><th>파일</th><th>크기</th>'
            . '<th>현재 상태</th><th>미리보기</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . '<div class="ojp-action-bar">'
            . ($collisions > 0
                ? '<label class="ojp-check"><input type="checkbox"'
                    . ' name="overwrite_collisions" value="1" required><span>'
                    . '같은 이름의 기존 파일 ' . $collisions
                    . '개 덮어쓰기</span></label>'
                : '<span>기존 파일과 이름 충돌이 없습니다.</span>')
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '선택 파일 적용 작업 등록</button></div></form></section>';
    }

    private function previewText(string $value, int $maximumBytes): string
    {
        if (strlen($value) <= $maximumBytes) {
            return $value;
        }
        $preview = substr($value, 0, $maximumBytes);
        while ($preview !== '' && preg_match('//u', $preview) !== 1) {
            $preview = substr($preview, 0, -1);
        }

        return $preview . "\n... 미리보기는 8KB까지만 표시합니다.";
    }

    private function fileCommands(ProblemId $problemId): string
    {
        $id = $problemId->value();

        return '<div class="ojp-action-bar"><div class="ojp-command-group">'
            . '<a class="ojp-button" href="/oj-console/problems/' . $id
            . '/test-data?new=1">새 파일</a></div>'
            . '<form class="ojp-control-row" method="post" enctype="multipart/form-data" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="upload">'
            . '<input type="hidden" name="problem_id" value="' . $id . '">'
            . '<label class="ojp-upload-inline"><span class="ojp-visually-hidden">'
            . '업로드할 테스트 파일</span><input type="file" name="test_data_file"'
            . ' accept=".in,.out,.ans,.txt,.c,.cc,.cpp,.log,.zip" required></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">업로드</button>'
            . '</form></div>';
    }

    /**
     * @param list<ProblemDataFile> $files
     */
    private function fileTable(
        ProblemId $problemId,
        array $files,
        ProblemTestDataInspection $inspection
    ): string {
        $rows = '';
        foreach ($files as $file) {
            $name = $this->html->escape($file->name());
            $rows .= '<tr><td><input type="checkbox" name="file_names[]" value="'
                . $name . '" data-ojp-select-item aria-label="' . $name . ' 선택">'
                . '<input type="hidden" name="file_hashes[' . $name . ']" value="'
                . $file->sha256() . '"></td><td><strong>' . $name . '</strong>'
                . '<span class="ojp-table-subvalue">' . $this->html->escape($file->kindLabel())
                . '</span></td><td>' . $this->formatBytes($file->size()) . '</td><td>'
                . $this->html->escape($this->html->date($file->modifiedAt()))
                . '</td><td>'
                . $this->pairStatus($file, $inspection) . '</td><td>'
                . '<div class="ojp-command-group">'
                . ($file->isEditable()
                    ? '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                        . $problemId->value() . '/test-data?file=' . rawurlencode($file->name())
                        . '">편집</a>'
                    : '')
                . ($file->extension() === 'zip'
                    ? '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                        . $problemId->value() . '/test-data?archive='
                        . rawurlencode($file->name()) . '">내용 확인</a>'
                    : '')
                . '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                . $problemId->value() . '/test-data?download='
                . rawurlencode($file->name()) . '">다운로드</a></div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">등록된 테스트 파일이 없습니다.</td></tr>';
        }

        return '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-ojp-bulk-select>'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<div class="ojp-bulk-bar" data-ojp-bulk-bar hidden><span><strong '
            . 'data-ojp-selected-count>0</strong>개 선택</span>'
            . '<div class="ojp-command-group"><label class="ojp-field ojp-field--inline">'
            . '<span class="ojp-visually-hidden">만들 ZIP 이름</span>'
            . '<input class="ojp-input" type="text" name="archive_name" value="cases.zip"'
            . ' aria-label="만들 ZIP 이름"></label>'
            . '<button class="ojp-button" type="submit" name="operation" value="create_zip">'
            . 'ZIP 만들기</button>'
            . '<button class="ojp-button ojp-button--danger" type="submit" name="operation"'
            . ' value="delete">선택 파일 삭제</button></div>'
            . '</div><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th><input type="checkbox" data-ojp-select-all aria-label="전체 선택">'
            . '</th><th>파일</th><th>크기</th><th>수정 시각</th><th>검사</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></form>';
    }

    private function editor(
        ProblemId $problemId,
        ?ProblemDataFile $selected,
        bool $newFile,
        int $userId
    ): string {
        if ($selected === null && ! $newFile) {
            return '<aside class="ojp-file-editor ojp-empty-state"><div><h2>파일 편집</h2>'
                . '<p>새 파일을 만들거나 편집할 UTF-8 텍스트 파일을 선택하세요.</p></div></aside>';
        }
        $fileName = $selected?->name() ?? '';
        $contents = $selected === null
            ? ''
            : $this->testData->read($userId, $problemId, $fileName);
        $expectedHash = $selected?->sha256() ?? '';

        return '<aside class="ojp-file-editor"><h2>'
            . ($selected === null ? '새 파일' : '파일 편집') . '</h2>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="save">'
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="expected_sha256" value="' . $expectedHash . '">'
            . '<label class="ojp-field"><span class="ojp-label">파일 이름</span>'
            . '<input class="ojp-input" type="text" name="file_name" value="'
            . $this->html->escape($fileName) . '"'
            . ($selected === null ? '' : ' readonly') . ' required></label>'
            . '<label class="ojp-field"><span class="ojp-label">UTF-8 내용</span>'
            . '<textarea class="ojp-textarea ojp-code-editor" name="contents" rows="22">'
            . $this->html->escape($contents) . '</textarea></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">파일 저장</button>'
            . '</form>'
            . ($selected === null ? '' : $this->renameForm($problemId, $selected))
            . '</aside>';
    }

    private function renameForm(
        ProblemId $problemId,
        ProblemDataFile $file
    ): string {
        return '<form class="ojp-form-stack ojp-file-rename" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="rename">'
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="file_name" value="'
            . $this->html->escape($file->name()) . '">'
            . '<input type="hidden" name="expected_sha256" value="' . $file->sha256() . '">'
            . '<label class="ojp-field"><span class="ojp-label">새 파일 이름</span>'
            . '<input class="ojp-input" type="text" name="new_file_name" value="'
            . $this->html->escape($file->name()) . '" required></label>'
            . '<button class="ojp-button" type="submit">이름 변경</button></form>';
    }

    /**
     * @param list<ProblemDataFile> $files
     */
    private function selectedFile(array $files, string $selectedName): ?ProblemDataFile
    {
        if ($selectedName === '') {
            return null;
        }
        foreach ($files as $file) {
            if ($file->name() === $selectedName && $file->isEditable()) {
                return $file;
            }
        }

        throw new DomainException('편집할 수 있는 파일을 찾을 수 없습니다.');
    }

    private function pairStatus(
        ProblemDataFile $file,
        ProblemTestDataInspection $inspection
    ): string {
        if (in_array($file->name(), $inspection->emptyFiles(), true)) {
            return '<span class="ojp-status-badge ojp-status-badge--warning">빈 파일</span>';
        }
        if (
            in_array($file->name(), $inspection->missingInputs(), true)
            || in_array($file->name(), $inspection->missingOutputs(), true)
        ) {
            return '<span class="ojp-status-badge ojp-status-badge--warning">짝 없음</span>';
        }
        if (in_array($file->name(), $inspection->duplicateOutputs(), true)) {
            return '<span class="ojp-status-badge ojp-status-badge--warning">출력 중복</span>';
        }
        if (in_array($file->extension(), ['in', 'out', 'ans'], true)) {
            return '<span class="ojp-status-badge ojp-status-badge--success">연결</span>';
        }

        return '<span class="ojp-status-badge ojp-status-badge--neutral">해당 없음</span>';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }

    /**
     * @param list<ProblemDataFile> $files
     */
    private function spjPanel(
        ProblemId $problemId,
        JudgeProblem $problem,
        array $files
    ): string {
        if (! $problem->specialJudge()) {
            return '<section class="ojp-file-editor ojp-spj-panel"><h2>특수 채점기</h2>'
                . '<p>문제 편집에서 특수 채점기 사용을 켜면 SPJ 컴파일을 사용할 수 있습니다.</p>'
                . '<span class="ojp-status-badge ojp-status-badge--neutral">사용 안 함</span>'
                . '</section>';
        }
        $indexed = [];
        foreach ($files as $file) {
            $indexed[$file->name()] = $file;
        }
        $sources = array_values(array_filter(
            ['spj.c', 'spj.cc', 'spj.cpp'],
            static fn (string $name): bool => isset($indexed[$name])
        ));
        $binary = $indexed['spj'] ?? null;
        $log = $indexed['spj-build.log'] ?? null;
        $options = '';
        foreach ($sources as $source) {
            $options .= '<option value="' . $source . '">' . $source . '</option>';
        }
        if ($options === '') {
            return '<section class="ojp-file-editor ojp-spj-panel"><h2>특수 채점기</h2>'
                . '<p><code>spj.c</code>, <code>spj.cc</code>, <code>spj.cpp</code> 중'
                . ' 하나를 먼저 생성하거나 업로드해 주세요.</p>'
                . '<span class="ojp-status-badge ojp-status-badge--warning">소스 필요</span>'
                . '</section>';
        }

        return '<section class="ojp-file-editor ojp-spj-panel"><h2>특수 채점기</h2>'
            . '<dl class="ojp-summary-list"><div><dt>현재 실행 파일</dt><dd>'
            . ($binary instanceof ProblemDataFile
                ? $this->formatBytes($binary->size())
                : '없음')
            . '</dd></div><div><dt>최근 컴파일 로그</dt><dd>'
            . ($log instanceof ProblemDataFile
                ? '<a href="/oj-console/problems/' . $problemId->value()
                    . '/test-data?file=spj-build.log">열기</a>'
                : '없음')
            . '</dd></div></dl>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="compile_spj">'
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="return_tab" value="spj">'
            . '<label class="ojp-field"><span class="ojp-label">SPJ 소스</span>'
            . '<select class="ojp-select" name="source_name" required>' . $options
            . '</select></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . 'SPJ 컴파일 작업 등록</button></form></section>';
    }

    private function actionForm(
        string $operation,
        ProblemId $problemId,
        string $tab,
        string $body,
        bool $multipart = false
    ): string {
        return '<form class="ojp-form-stack" method="post"'
            . ($multipart ? ' enctype="multipart/form-data"' : '')
            . ' action="' . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="' . $operation . '">'
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="return_tab" value="' . $this->html->escape($tab) . '">'
            . $body . '</form>';
    }

    private function stepBadge(string $label, string $tone): string
    {
        return '<span class="ojp-status-badge ojp-status-badge--' . $tone . '">'
            . $this->html->escape($label) . '</span>';
    }

    private function visualStep(
        int $number,
        string $title,
        string $badge,
        string $body,
        bool $done
    ): string {
        return '<li class="ojp-visual-step' . ($done ? ' is-done' : '') . '">'
            . '<div class="ojp-visual-step__head"><span class="ojp-visual-step__number">'
            . ($done ? '✓' : (string) $number) . '</span><h3>'
            . $this->html->escape($title) . '</h3>' . $badge . '</div>'
            . '<div class="ojp-visual-step__body">' . $body . '</div></li>';
    }

    /**
     * @param list<ProblemDataFile> $files
     */
    private function visualGradingPanel(
        ProblemId $problemId,
        int $userId,
        array $files
    ): string {
        if ($this->visualGrading === null) {
            return '';
        }
        $mode = $this->visualGrading->mode($problemId);
        if (! $mode->isEnabled()) {
            return '';
        }
        $indexed = [];
        foreach ($files as $file) {
            $indexed[$file->name()] = $file;
        }
        $source = $indexed[VisualGradingService::SPJ_SOURCE] ?? null;
        $binary = $indexed['spj'] ?? null;
        try {
            $previews = $this->visualGrading->referencePreviews($userId, $problemId);
            $wrapper = $this->visualGrading->wrapperFor($mode);
        } catch (DomainException) {
            $previews = [];
            $wrapper = null;
        }
        try {
            $capture = $this->visualGrading->captureState($userId, $problemId);
        } catch (DomainException) {
            $capture = null;
        }
        $tab = 'visual';
        try {
            $tolerance = $this->visualGrading->toleranceState($userId, $problemId);
        } catch (DomainException) {
            $tolerance = ['value' => VisualGradingService::DEFAULT_TOLERANCE, 'custom' => false, 'problem' => null];
        }

        $outdated = $source instanceof ProblemDataFile
            && ! $this->visualGrading->isGraderCurrent($source->sha256());
        $graderBadge = $outdated
            ? $this->stepBadge('새 버전 있음', 'warning')
            : ($binary instanceof ProblemDataFile
            ? $this->stepBadge('준비됨', 'success')
            : ($source instanceof ProblemDataFile
                ? $this->stepBadge('컴파일 확인 필요', 'warning')
                : $this->stepBadge('설치 필요', 'warning')));
        $graderBody = ($outdated
                ? '<div class="ojp-notice ojp-notice--warning"><p>문제 폴더의 spj.cc가 이 플러그인에 내장된 최신 채점기와 다릅니다.'
                    . ' 최신 기능(비교 기준 등)을 쓰려면 아래 "덮어쓰기"를 체크한 채로 설치·컴파일해 주세요.</p></div>'
                : '')
            . '<p class="ojp-muted">' . $this->installGuidance($source, $binary) . '</p>'
            . '<dl class="ojp-summary-list"><div><dt>채점기 소스</dt><dd>'
            . ($source instanceof ProblemDataFile
                ? 'spj.cc (' . $this->formatBytes($source->size()) . ')'
                : '없음')
            . '</dd></div><div><dt>채점기 실행 파일</dt><dd>'
            . ($binary instanceof ProblemDataFile
                ? $this->formatBytes($binary->size())
                : ($source instanceof ProblemDataFile ? '없음 (컴파일 전)' : '없음'))
            . '</dd></div></dl>'
            . $this->actionForm(
                'install_visual_grader',
                $problemId,
                $tab,
                '<label class="ojp-check"><input type="checkbox" name="replace_existing" value="1"'
                . ($outdated ? ' checked' : '') . '>'
                . '<span>다른 내용의 기존 spj.cc 덮어쓰기</span></label>'
                . '<button class="ojp-button ojp-button--primary" type="submit">'
                . ($binary instanceof ProblemDataFile
                    ? '채점기 다시 설치·컴파일' : '이미지 채점기 설치·컴파일 작업 등록')
                . '</button>'
            );

        $methodA = '<section class="ojp-visual-method"><h4>방법 A · 모범 답안으로 자동 만들기'
            . ' <span class="ojp-status-badge ojp-status-badge--success">권장</span></h4>'
            . ($capture === null
                ? '<p class="ojp-muted">캡처 상태를 읽지 못했습니다.</p>'
                : $this->captureControls($problemId, $capture))
            . '</section>';
        $methodB = '<section class="ojp-visual-method"><h4>방법 B · PNG 파일 업로드</h4>'
            . '<p class="ojp-muted">직접 만든 PNG를 올립니다. 채점 서버와 같은 환경에서 만든 이미지여야 안정적으로 비교됩니다.</p>'
            . $this->actionForm(
                'upload_reference_image',
                $problemId,
                $tab,
                '<label class="ojp-field"><span class="ojp-label">테스트 이름</span>'
                . '<input class="ojp-input" type="text" name="case_name" value="01"'
                . ' pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" maxlength="64" required></label>'
                . '<label class="ojp-field"><span class="ojp-label">학생 프로그램 입력 (비워 두면 빈 입력)</span>'
                . '<textarea class="ojp-textarea" name="case_input" rows="2"></textarea></label>'
                . '<label class="ojp-field"><span class="ojp-label">정답 PNG (8MB 이하, 2048×2048 이하)</span>'
                . '<input type="file" name="reference_image" accept=".png,image/png" required></label>'
                . '<label class="ojp-check"><input type="checkbox" name="replace_existing" value="1">'
                . '<span>같은 이름 덮어쓰기</span></label>'
                . '<button class="ojp-button" type="submit">정답 이미지 업로드</button>',
                true
            )
            . '</section>';

        $gallery = '';
        foreach ($previews as $preview) {
            $gallery .= '<figure class="ojp-reference-image"><img src="'
                . $this->html->escape($preview['source']) . '" alt="'
                . $this->html->escape($preview['name']) . ' 정답 이미지" width="'
                . $preview['width'] . '" height="' . $preview['height']
                . '" loading="lazy"><figcaption>'
                . $this->html->escape($preview['name']) . ' · '
                . $preview['width'] . '×' . $preview['height'] . '</figcaption></figure>';
        }
        $referenceBody = $gallery === ''
            ? '<p class="ojp-muted">아직 등록된 정답 이미지가 없습니다. 위 방법 A 또는 B로 만들어 주세요.</p>'
            : '<div class="ojp-reference-gallery">' . $gallery . '</div>';

        return '<div class="ojp-visual-grading-panel">'
            . '<p class="ojp-visual-intro"><strong>' . $this->html->escape($mode->label())
            . '</strong> · 학생 코드 앞뒤에 그림을 PNG로 내보내는 비공개 코드가 자동으로 붙고,'
            . ' 채점기가 정답 이미지와 비교합니다. 아래 순서대로 진행하세요.</p>'
            . ($capture === null ? '' : $this->verdictNotice($problemId, $capture, $previews))
            . '<ol class="ojp-visual-steps">'
            . $this->visualStep(1, '채점기 준비', $graderBadge, $graderBody, $binary instanceof ProblemDataFile)
            . $this->visualStep(
                2,
                '정답 이미지 만들기',
                $this->stepBadge($previews === [] ? '필요' : '완료', $previews === [] ? 'warning' : 'success'),
                '<div class="ojp-visual-methods">' . $methodA . $methodB . '</div>',
                $previews !== []
            )
            . $this->visualStep(
                3,
                '등록된 정답 이미지',
                $this->stepBadge(count($previews) . '개', 'neutral'),
                $referenceBody,
                false
            )
            . $this->visualStep(
                4,
                '비교 기준 (선택)',
                $this->stepBadge(
                    $tolerance['custom'] ? '색 허용치 ' . $tolerance['value'] : '기본 · 엄격',
                    $tolerance['custom'] ? 'warning' : 'neutral'
                ),
                $this->toleranceControls($problemId, $tolerance),
                false
            )
            . '</ol>'
            . ($wrapper === null ? '' : '<details class="ojp-disclosure"><summary>'
                . '자동으로 붙는 코드 보기</summary>'
                . '<p class="ojp-muted">직접 정답을 만들려면 출제자 풀이를 앞 코드와 뒤 코드 사이에 넣어 채점 서버와 같은 Python 환경에서 실행하고,'
                . ' 표준 출력으로 나온 PNG를 업로드하세요. 방법 A를 쓰면 필요 없습니다.</p>'
                . '<h4>앞 코드</h4><pre class="ojp-code-block"><code>'
                . $this->html->escape($wrapper->prefix()) . '</code></pre>'
                . '<h4>뒤 코드</h4><pre class="ojp-code-block"><code>'
                . $this->html->escape($wrapper->suffix()) . '</code></pre></details>')
            . '</div>';
    }

    /**
     * @param list<array{name: string, width: int, height: int, source: string}> $previews
     */
    private function verdictNotice(ProblemId $problemId, VisualCaptureState $capture, array $previews): string
    {
        $verdict = $capture->lastVerdict;
        if ($verdict === null) {
            return '';
        }
        $line = $verdict['line'];
        $values = [];
        if (preg_match_all('/(\w+)=(\S+)/', $line, $pairs, PREG_SET_ORDER) > 0) {
            foreach ($pairs as $pair) {
                $values[$pair[1]] = $pair[2];
            }
        }
        $kind = preg_match('/^OJ_IMAGE_SPJ\/1 (\w+)/', $line, $kindMatch) === 1 ? $kindMatch[1] : '';
        $reason = $values['reason'] ?? '';
        [$tone, $text] = match (true) {
            $kind === 'AC' => ['success', '정답 이미지와 일치해 정답 처리되었습니다.'],
            $kind === 'CAPTURED' => ['success', '정답 후보를 캡처했습니다.'],
            $kind === 'WA' && $reason === 'dimensions_mismatch' => ['warning',
                '이미지 크기가 다릅니다. 정답 ' . ($values['expected'] ?? '?')
                . ' · 제출 ' . ($values['actual'] ?? '?')],
            $kind === 'WA' && $reason === 'pixels_differ' => ['warning',
                '크기는 같지만 픽셀이 다릅니다. 정답에만 있는 픽셀 ' . ($values['unmatched_expected'] ?? '?')
                . '개 · 제출에만 있는 픽셀 ' . ($values['unmatched_actual'] ?? '?') . '개'
                . (isset($values['diff_bbox']) ? ' · 차이 범위(x,y 시작~끝) ' . $values['diff_bbox'] : '')
                . (isset($values['max_delta']) ? ' · 최대 색 차이 ' . $values['max_delta'] : '')],
            $kind === 'WA' => ['warning', '제출 출력이 올바른 PNG가 아닙니다 (' . $reason . ').'],
            $kind === 'CHECKER_ERROR' => ['danger', '채점기 오류로 런타임 오류가 표시됩니다: ' . $reason],
            default => ['neutral', '알 수 없는 기록입니다.'],
        };
        $minutes = max(0, intdiv(time() - $verdict['time'], 60));
        $comparison = '';
        $expected = null;
        foreach ($previews as $preview) {
            if (($values['case'] ?? '') !== '' && $preview['name'] === $values['case'] . '.out') {
                $expected = $preview;
            }
        }
        if ($kind === 'WA' && $capture->lastRejected !== null && $expected !== null) {
            $actual = $capture->lastRejected;
            $comparison = '<div class="ojp-diff-compare">'
                . '<figure class="ojp-reference-image"><img src="' . $this->html->escape($expected['source'])
                . '" alt="정답 이미지"><figcaption>정답 ' . $expected['width'] . '×' . $expected['height']
                . '</figcaption></figure>'
                . '<figure class="ojp-reference-image"><img src="' . $this->html->escape($actual['source'])
                . '" alt="마지막 제출 이미지"><figcaption>마지막 제출 ' . $actual['width'] . '×' . $actual['height']
                . '</figcaption></figure>'
                . '<figure class="ojp-reference-image"><div class="ojp-diff-overlay"><img src="'
                . $this->html->escape($expected['source']) . '" alt=""><img src="'
                . $this->html->escape($actual['source']) . '" alt="" class="ojp-diff-overlay__top"></div>'
                . '<figcaption>겹쳐 보기 · 검은색이면 같고 색이 보이면 다른 부분</figcaption></figure></div>';
        }

        if (($values['settings'] ?? '') === 'unreadable') {
            $text .= ' · 채점기가 ' . VisualGradingService::SETTINGS_FILE
                . '을 읽지 못해 기본 엄격 기준(색 허용치 ' . VisualGradingService::DEFAULT_TOLERANCE
                . ')으로 채점했습니다. 서버에서 이 파일을 채점기 계정이 읽을 수 있게 해 주세요'
                . ' (예: chmod 644).';
            $tone = 'warning';
        }
        $suggestion = '';
        $maxDelta = (int) ($values['max_delta'] ?? 0);
        $suggested = $kind === 'WA' && $reason === 'pixels_differ'
            ? VisualGradingService::suggestedTolerance($maxDelta)
            : null;
        if ($suggested !== null && $suggested > (int) ($values['color_tolerance'] ?? 0)) {
            $suggestion = '<div class="ojp-tolerance-suggestion"><p>이 제출의 최대 색 차이는 ' . $maxDelta
                . '입니다. 이 차이를 허용하려면 색 허용치를 <strong>' . $suggested
                . '</strong>로 설정하면 됩니다. 틀려야 하는 그림(마커가 빠지거나 선이 달라진 경우)도'
                . ' 같은 값으로 통과하지 않는지 한 번 확인해 주세요.</p>'
                . $this->actionForm(
                    'set_tolerance',
                    $problemId,
                    'visual',
                    '<input type="hidden" name="tolerance" value="' . $suggested . '">'
                    . '<button class="ojp-button ojp-button--primary" type="submit">색 허용치를 '
                    . $suggested . '로 설정</button>'
                ) . '</div>';
        }

        return '<div class="ojp-notice ojp-notice--' . ($tone === 'neutral' ? 'info' : $tone)
            . '"><strong>채점기 마지막 판정 · '
            . ($minutes < 1 ? '방금 전' : $minutes . '분 전') . '</strong><p>'
            . $this->html->escape($text) . '</p>' . $comparison . $suggestion
            . '<details><summary>원본 기록</summary><code>'
            . $this->html->escape($line) . '</code></details></div>';
    }

    /**
     * @param array{value: int, custom: bool, problem: string|null} $tolerance
     */
    private function toleranceControls(ProblemId $problemId, array $tolerance): string
    {
        $max = VisualGradingService::MAX_TOLERANCE;
        $html = '<p class="ojp-muted">같은 그림이라도 그린 순서나 시작점이 달라 가장자리 번짐이 조금 다를 수 있습니다.'
            . ' 색 허용치는 픽셀 하나의 색 차이를 어디까지 같은 것으로 볼지 정하는 값입니다.'
            . ' 기본값은 ' . VisualGradingService::DEFAULT_TOLERANCE . '(엄격)이며 최대 ' . $max . '까지 설정할 수 있습니다.</p>'
            . '<ul class="ojp-muted"><li>정하는 법: 통과시킬 제출의 "최대 색 차이"보다 조금 크고,'
            . ' 틀려야 하는 그림의 "최대 색 차이"보다 충분히 작게 둡니다.</li>'
            . '<li>연한 색(연노랑·연회색) 선이나 마커가 있는 그림은 값을 키우면 빠져도 통과할 수 있으니 64 이하를 권합니다.</li></ul>';
        if ($tolerance['problem'] !== null) {
            $html .= '<div class="ojp-notice ojp-notice--warning"><p>'
                . $this->html->escape($tolerance['problem']) . '</p></div>';
        }
        $html .= $this->actionForm(
            'set_tolerance',
            $problemId,
            'visual',
            '<label class="ojp-field"><span class="ojp-label">색 허용치 (0~' . $max . ')</span>'
            . '<input class="ojp-input" type="number" name="tolerance" min="0" max="' . $max
            . '" value="' . $tolerance['value'] . '" required></label>'
            . '<button class="ojp-button" type="submit">비교 기준 저장</button>'
        );
        if ($tolerance['custom'] || $tolerance['problem'] !== null) {
            $html .= $this->actionForm(
                'set_tolerance',
                $problemId,
                'visual',
                '<input type="hidden" name="tolerance" value="' . VisualGradingService::DEFAULT_TOLERANCE . '">'
                . '<button class="ojp-button" type="submit">기본값(엄격)으로 되돌리기</button>'
            );
        }

        return $html;
    }

    private function installGuidance(?ProblemDataFile $source, ?ProblemDataFile $binary): string
    {
        if ($binary instanceof ProblemDataFile) {
            return '채점기 실행 파일이 있습니다. 소스를 바꾸거나 새 버전이 필요할 때만 다시 설치·컴파일하세요.';
        }
        if ($source instanceof ProblemDataFile) {
            return '소스는 저장됐지만 실행 파일이 아직 없습니다. 컴파일은 작업 대기열에서 비동기로 처리되므로'
                . ' 대기 중이거나 실패했을 수 있습니다. 잠시 뒤 새로고침하고, 계속 없으면 '
                . '<a href="/oj-console/jobs">작업 목록</a>에서 컴파일 작업의 상태와 실패 사유를 확인하세요.';
        }

        return '버튼을 누르면 이미지 채점기 소스(spj.cc)를 문제 폴더에 저장하고 컴파일 작업을 등록합니다.'
            . ' 컴파일은 대기열에서 처리되므로 실행 파일은 몇 분 뒤에 나타날 수 있습니다.';
    }

    private function captureControls(
        ProblemId $problemId,
        VisualCaptureState $capture
    ): string {
        $tab = 'visual';
        $html = '<p class="ojp-muted">모범 답안을 이 문제에 한 번 제출하면 채점 환경이 만든 PNG를 정답 후보로 저장합니다.</p>';
        if (! $capture->directoryReady) {
            $path = $capture->directoryPath;
            return $html . '<div class="ojp-notice ojp-notice--warning"><strong>서버에 캡처 폴더가 필요합니다.</strong>'
                . '<p>채점 서버에서 한 번만 아래를 실행해 주세요. 정답 파일이 있는 문제 폴더에는 쓰기 권한을 주지 않아도 됩니다.</p>'
                . '<pre class="ojp-code-block"><code>' . $this->html->escape(
                    'sudo mkdir ' . $path . "\n"
                    . 'sudo chown www-data:judge ' . $path . "\n"
                    . 'sudo chmod 2775 ' . $path
                ) . '</code></pre></div>'
                . $this->actionForm(
                    'arm_capture',
                    $problemId,
                    $tab,
                    '<button class="ojp-button" type="submit" disabled>정답 캡처 시작</button>'
                );
        }
        $flow = static function (int $active): string {
            $labels = ['캡처 시작', '모범 답안 제출', '후보 확인·적용'];
            $items = '';
            foreach ($labels as $index => $label) {
                $items .= '<li' . ($index + 1 === $active ? ' class="is-active"' : '')
                    . '>' . ($index + 1) . '. ' . $label . '</li>';
            }

            return '<ol class="ojp-visual-flow">' . $items . '</ol>';
        };
        if ($capture->candidate !== null) {
            $candidate = $capture->candidate;
            $suggested = $candidate['case'] === 'capture-tmp' ? '01' : $candidate['case'];

            return $html . $flow(3)
                . '<figure class="ojp-reference-image"><img src="'
                . $this->html->escape($candidate['source'])
                . '" alt="캡처한 정답 후보" width="' . $candidate['width']
                . '" height="' . $candidate['height'] . '"><figcaption>정답 후보 · '
                . $candidate['width'] . '×' . $candidate['height'] . '</figcaption></figure>'
                . $this->actionForm(
                    'apply_capture',
                    $problemId,
                    $tab,
                    '<label class="ojp-field"><span class="ojp-label">테스트 이름</span>'
                    . '<input class="ojp-input" type="text" name="case_name" value="'
                    . $this->html->escape($suggested)
                    . '" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" maxlength="64" required></label>'
                    . '<label class="ojp-field"><span class="ojp-label">학생 프로그램 입력 (비워 두면 빈 입력)</span>'
                    . '<textarea class="ojp-textarea" name="case_input" rows="2"></textarea></label>'
                    . '<label class="ojp-check"><input type="checkbox" name="replace_existing" value="1">'
                    . '<span>같은 이름 덮어쓰기</span></label>'
                    . '<button class="ojp-button ojp-button--primary" type="submit">'
                    . '정답 이미지로 적용</button>'
                )
                . $this->actionForm(
                    'cancel_capture',
                    $problemId,
                    $tab,
                    '<button class="ojp-button" type="submit">캡처 취소·임시 파일 정리</button>'
                );
        }
        if ($capture->candidateProblem !== null) {
            $html .= '<div class="ojp-notice ojp-notice--warning"><p>'
                . $this->html->escape($capture->candidateProblem) . '</p></div>';
        }
        if ($capture->armed) {
            return $html . $flow(2)
                . '<p><strong>캡처 대기 중</strong> · 약 ' . (int) ceil($capture->remainingSeconds / 60)
                . '분 남음</p><p><a class="ojp-button ojp-button--primary" href="/submit/'
                . $problemId->value() . '">모범 답안 제출하러 가기</a></p>'
                . $this->actionForm(
                    'cancel_capture',
                    $problemId,
                    $tab,
                    '<button class="ojp-button" type="submit">캡처 취소·임시 파일 정리</button>'
                );
        }
        if ($capture->expired) {
            $html .= '<p class="ojp-muted">캡처 시간이 지났습니다. 다시 시작해 주세요.</p>';
        }

        return $html . $flow(1)
            . $this->actionForm(
                'arm_capture',
                $problemId,
                $tab,
                '<button class="ojp-button ojp-button--primary" type="submit">정답 캡처 시작</button>'
            )
            . ($capture->expired
                ? $this->actionForm(
                    'cancel_capture',
                    $problemId,
                    $tab,
                    '<button class="ojp-button" type="submit">임시 파일 정리</button>'
                )
                : '');
    }

    private function aiTestcasePanel(
        ProblemId $problemId,
        int $userId
    ): string {
        if (! $this->access->canRunTestcaseAi($userId, $problemId)) {
            return '';
        }

        return '<section class="ojp-file-editor">'
            . '<h2>AI 테스트케이스 초안</h2>'
            . '<p>생성 결과는 암호화 초안에 보관하며 확인 전에는 문제 폴더를 바꾸지 않습니다.</p>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->ajaxUrl())
            . '" data-ojp-ai-job-form>'
            . $this->security->hiddenFields('oj_problem_ai_generate')
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<label class="ojp-field"><span class="ojp-label">생성 방식</span>'
            . '<select class="ojp-select" name="ai_operation">'
            . '<option value="testcase_file">입출력 파일 초안</option>'
            . '<option value="generator_bundle">생성 기준을 포함한 파일 초안</option>'
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">입출력 쌍 수</span>'
            . '<input class="ojp-input" type="number" name="pair_count"'
            . ' value="3" min="1" max="20" required></label>'
            . '<label class="ojp-field"><span class="ojp-label">추가 조건</span>'
            . '<textarea class="ojp-textarea" name="guidance" rows="5"></textarea></label>'
            . '<span class="ojp-form-status" data-ojp-ai-job-status'
            . ' aria-live="polite"></span>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . 'AI 초안 작업 등록</button></form></section>';
    }

    private function archivePreview(
        ProblemId $problemId,
        ProblemArchiveInspection $archive
    ): string {
        $rows = '';
        foreach ($archive->entries() as $entry) {
            $rows .= '<tr><td><code>' . $this->html->escape($entry->name())
                . '</code></td><td>' . $this->formatBytes($entry->uncompressedBytes())
                . '</td><td><span class="ojp-status-badge ojp-status-badge--success">'
                . '안전</span></td><td>'
                . ($entry->hasCollision()
                    ? '<span class="ojp-status-badge ojp-status-badge--warning">이름 충돌</span>'
                    : '새 파일')
                . '</td></tr>';
        }

        return '<section class="ojp-section ojp-archive-preview"><header class="ojp-section-heading">'
            . '<div><p class="ojp-eyebrow">' . $this->html->escape($archive->archiveName())
            . '</p><h2>압축 풀기 전 확인</h2><p>검증된 루트 파일만 적용합니다.</p></div>'
            . '<a class="ojp-button" href="/oj-console/problems/' . $problemId->value()
            . '/test-data">닫기</a></header>'
            . '<div class="ojp-metric-grid"><article><strong>' . $archive->entryCount()
            . '</strong><span>파일</span></article><article><strong>'
            . $this->formatBytes($archive->totalUncompressedBytes())
            . '</strong><span>해제 크기</span></article><article><strong>'
            . $archive->collisionCount() . '</strong><span>이름 충돌</span></article></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>파일</th><th>크기</th><th>검사</th><th>적용</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_test_data_action')
            . '<input type="hidden" name="operation" value="extract_zip">'
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="archive_name" value="'
            . $this->html->escape($archive->archiveName()) . '">'
            . '<input type="hidden" name="archive_sha256" value="'
            . $archive->archiveSha256() . '">'
            . ($archive->collisionCount() > 0
                ? '<label class="ojp-check"><input type="checkbox"'
                    . ' name="overwrite_collisions" value="1"><span>같은 이름의 파일 '
                    . $archive->collisionCount() . '개 덮어쓰기</span></label>'
                : '')
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '검증 후 압축 풀기</button></form></section>';
    }
}
