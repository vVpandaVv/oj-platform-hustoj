<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use DomainException;

final class ClassManagementPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ClassService $classes,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ClassHtml $html,
        private readonly ChoiceProvider $groupChoices,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $routeName = $route->route()->key();
        if (in_array(
            $routeName,
            ['console.classes.index', 'console.classes.deleted'],
            true
        )) {
            $deleted = $routeName === 'console.classes.deleted';
            $allClasses = array_values(array_filter(
                $this->classes->managedBy($userId),
                static fn (LearningClass $class): bool =>
                    $class->isDeleted() === $deleted
            ));
            $basePath = $deleted ? '/oj-console/classes/deleted' : '/oj-console/classes';
            $page = max(1, (int) $request->queryString('page', '1'));
            $pageCount = max(1, (int) ceil(count($allClasses) / self::PAGE_SIZE));
            $page = min($page, $pageCount);
            $classes = array_slice($allClasses, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            $title = $deleted ? '보관된 수업' : '수업 관리';
            $addButton = ! $deleted && $this->classes->canCreate($userId)
                ? '<a class="ojp-button ojp-button--primary"'
                    . ' href="/oj-console/classes/new">수업 추가</a>'
                : '';
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습 공간</p><h1>' . $title . '</h1>'
                . '<p>수업 문제, 참여자와 학습 현황을 한 곳에서 관리합니다.</p>'
                . '</div><div class="ojp-command-group">'
                . $addButton . '</div></header>'
                . $this->html->classTable(
                    $classes,
                    true,
                    ! $deleted && $this->classes->canCreate($userId),
                    fn (LearningClass $class): bool => $this->classes->canArchive($userId, $class)
                )
                . $this->pagination->links($page, $pageCount, $basePath);

            return $this->pages->respond(
                $title,
                $content,
                $deleted ? 'classes.deleted' : 'classes.index',
                $request
            );
        }

        $classId = (int) ($route->parameters()['class_id'] ?? 0);
        $class = null;
        if ($classId > 0) {
            try {
                $class = $this->classes->findManaged($classId, $userId);
            } catch (DomainException $error) {
                return $this->pages->respond(
                    '수업 관리',
                    '<div class="ojp-empty-state"><h1>수업을 관리할 수 없습니다.</h1><p>'
                        . $this->html->escape($error->getMessage()) . '</p></div>',
                    'classes.index',
                    $request,
                    403
                );
            }
        }
        if ($routeName === 'console.classes.delete' && $class !== null) {
            $restore = $class->isDeleted();
            $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
                . '수업 관리</p><h1>' . ($restore ? '수업 복원' : '수업 보관')
                . '</h1><p>' . $this->html->escape($class->name())
                . '의 문제 연결과 학습 기록은 유지됩니다.</p></div></header>'
                . '<form class="ojp-form" method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_class_lifecycle_command')
                . '<input type="hidden" name="class_id" value="' . $classId . '">'
                . '<input type="hidden" name="operation" value="'
                . ($restore ? 'restore' : 'archive') . '">'
                . '<button class="ojp-button ojp-button--primary" type="submit">'
                . ($restore ? '복원' : '보관') . '</button></form>';

            return $this->pages->respond(
                $restore ? '수업 복원' : '수업 보관',
                $content,
                $restore ? 'classes.deleted' : 'classes.index',
                $request
            );
        }

        $copy = null;
        if ($routeName === 'console.classes.new' && (int) $request->queryString('copy_from', '0') > 0) {
            try {
                $copy = $this->classes->copyDraft($userId, (int) $request->queryString('copy_from'));
            } catch (DomainException $error) {
                return $this->pages->respond(
                    '수업 복사',
                    '<div class="ojp-empty-state"><h1>수업을 복사할 수 없습니다.</h1><p>'
                        . $this->html->escape($error->getMessage()) . '</p></div>',
                    'classes.index',
                    $request,
                    403
                );
            }
        }
        $title = $copy !== null ? '수업 복사' : ($class === null ? '수업 추가' : '수업 수정');
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '학습 공간</p><h1>' . $title
            . '</h1><p>기본 정보와 담당자, 연결 그룹, 문제를 한 번에 저장합니다.</p>'
            . '</div>'
            . ($class !== null
                ? '<div class="ojp-command-group">'
                    . ($this->classes->canCreate($userId) ? $this->html->copyLink($classId) : '')
                    . ($this->classes->canArchive($userId, $class) ? $this->html->lifecycleLink($class) : '')
                    . '</div>'
                : '')
            . '</header>'
            . ($class === null ? '' : $this->html->tabs($classId, 'edit', true))
            . ($copy === null ? '' : '<div class="ojp-notice ojp-notice--info">'
                . '<strong>새 수업으로 저장됩니다.</strong>'
                . '<p>문제 구성과 연결 가능한 그룹을 가져왔습니다. 참여자·학습 기록·공동 강사·비밀번호·기간은 복사하지 않습니다. '
                . '새 수업 주소를 입력하고 공개 범위와 운영 여부를 확인해 주세요.</p></div>')
            . $this->form($class, $userId, $copy);

        return $this->pages->respond(
            $title,
            $content,
            $class === null ? 'classes.new' : 'classes.index',
            $request
        );
    }

    private function form(?LearningClass $class, int $userId, ?ClassSaveCommand $copy = null): string
    {
        $values = $copy ?? $class;
        $classId = $class?->id() ?? 0;
        $canChangeSettings = $class === null
            || $this->classes->canChangeSettings($userId, $class);
        $instructors = $classId > 0
            ? implode("\n", $this->classes->instructorLogins($classId))
            : '';
        $groupIds = $classId > 0
            ? $this->classes->groupIds($classId)
            : ($copy?->groupIds() ?? []);
        $problems = $classId > 0
            ? $this->classes->classProblems($classId)
            : array_values($copy?->problemConfigurations() ?? []);

        return '<form class="ojp-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_class_save')
            . '<input type="hidden" name="class_id" value="' . $classId . '">'
            . ($canChangeSettings
                ? ''
                : '<div class="ojp-notice ojp-notice--info"><strong>'
                    . '수업 기본 설정은 소유자만 변경할 수 있습니다.</strong>'
                    . '<p>공동 강사는 참여자, 연결 그룹과 문제 구성을 운영합니다.</p></div>'
                    . $this->immutableSettingsFields($class)
                    . '<fieldset class="ojp-fieldset" disabled>')
            . '<div class="ojp-form-grid">'
            . $this->input('name', '수업 이름', $values?->name() ?? '', true)
            . $this->input('slug', '수업 주소', $values?->slug() ?? '', true)
            . '<label class="ojp-field"><span class="ojp-label">공개 범위</span>'
            . '<select class="ojp-select" name="visibility">'
            . $this->option('public', '공개', $values?->visibility() ?? 'private')
            . $this->option('password', '비밀번호', $values?->visibility() ?? 'private')
            . $this->option('private', '비공개', $values?->visibility() ?? 'private')
            . $this->groupVisibilityOption($values?->visibility() ?? 'private')
            . '</select></label>'
            . '<label class="ojp-check"><input type="checkbox" name="approval_required" value="1"'
            . (($values?->requiresApproval() ?? false) ? ' checked' : '')
            . '><span>참여 승인 필요</span></label>'
            . '<label class="ojp-field"><span class="ojp-label">학습 현황 공개</span>'
            . '<select class="ojp-select" name="progress_visibility">'
            . $this->option('private', '담당자만', $values?->progressVisibility() ?? 'private')
            . $this->option(
                'participants_live',
                '참여자에게 실시간 공개',
                $values?->progressVisibility() ?? 'private'
            )
            . '</select></label>'
            . $this->input('starts_at', '시작 시각', $values?->startsAt() ?? '', false, 'datetime-local')
            . $this->input('ends_at', '종료 시각', $values?->endsAt() ?? '', false, 'datetime-local')
            . $this->input('password', '참여 비밀번호', '', false, 'password')
            . '<label class="ojp-field"><span class="ojp-label">Blockly 범위</span>'
            . '<select class="ojp-select" name="blockly_profile_key">'
            . $this->option('', '기본 설정', $values?->blocklyProfileKey() ?? '')
            . $this->option('intro', '입문', $values?->blocklyProfileKey() ?? '')
            . $this->option('standard', '표준', $values?->blocklyProfileKey() ?? '')
            . $this->option('extended', '확장', $values?->blocklyProfileKey() ?? '')
            . '</select></label></div>'
            . '<label class="ojp-field"><span class="ojp-label">설명</span>'
            . '<textarea class="ojp-textarea" name="description" rows="5">'
            . $this->html->escape($values?->description() ?? '') . '</textarea></label>'
            . $this->textarea(
                'instructor_user_logins',
                '공동 강사 로그인 아이디',
                $instructors
            )
            . ($canChangeSettings ? '' : '</fieldset>')
            . $this->groupSelection($userId, $groupIds)
            . $this->problemConfigurationEditor($problems)
            . '<label class="ojp-check"><input type="checkbox" name="enabled" value="1"'
            . (($values?->isEnabled() ?? true) ? ' checked' : '')
            . '> 수업 운영</label>'
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">' . ($copy === null ? '수업 저장' : '복사본 저장') . '</button></div></form>';
    }


    /** @param list<ClassProblem> $problems */
    private function problemConfigurationEditor(array $problems): string
    {
        $rows = '';
        foreach ($problems as $index => $problem) {
            $problemId = $problem->problemId();
            $from = $this->dateTimeInputValue($problem->availableFrom());
            $until = $this->dateTimeInputValue($problem->availableUntil());
            $schedule = $problem->isVisible() ? '즉시 공개' : '숨김';
            if ($problem->availableFrom() !== null) {
                $schedule = $problem->availableFrom() . '부터 공개';
            }
            if ($problem->availableUntil() !== null) {
                $schedule .= ' · ' . $problem->availableUntil() . '까지';
            }
            $rows .= '<div class="ojp-class-problem-row">'
                . '<input type="hidden" name="class_problem_ids[]" value="'
                . $problemId . '">'
                . '<input type="hidden" name="problem_configured[' . $problemId
                . ']" value="1">'
                . '<div class="ojp-class-problem-main"><strong>문제 '
                . $problemId . '</strong><span>'
                . $this->html->escape($schedule) . '</span></div>'
                . '<label class="ojp-field ojp-class-problem-order"><span class="ojp-label">순서</span>'
                . '<input class="ojp-input" type="number" min="1" name="problem_order['
                . $problemId . ']" value="' . ($index + 1) . '"></label>'
                . '<label class="ojp-check"><input type="checkbox" name="problem_visible['
                . $problemId . ']" value="1"'
                . ($problem->isVisible() ? ' checked' : '')
                . '><span><strong>공개</strong><small>체크 해제 시 학습자에게 숨깁니다.</small></span></label>'
                . '<label class="ojp-field"><span class="ojp-label">공개 시작</span>'
                . '<input class="ojp-input" type="datetime-local" name="problem_available_from['
                . $problemId . ']" value="' . $this->html->escape($from) . '"></label>'
                . '<label class="ojp-field"><span class="ojp-label">공개 종료</span>'
                . '<input class="ojp-input" type="datetime-local" name="problem_available_until['
                . $problemId . ']" value="' . $this->html->escape($until) . '"></label>'
                . '<label class="ojp-check ojp-check--danger"><input type="checkbox" name="problem_remove['
                . $problemId . ']" value="1"><span><strong>제외</strong>'
                . '<small>저장할 때 수업에서 제거합니다.</small></span></label></div>';
        }
        if ($rows === '') {
            $rows = '<div class="ojp-empty-state"><strong>등록된 문제가 없습니다.</strong>'
                . '<p>아래에 문제 번호를 입력하면 즉시 공개 상태로 추가됩니다.</p></div>';
        }

        return '<section class="ojp-form-section ojp-class-problem-editor">'
            . '<div class="ojp-section-heading"><div><h2>문제 구성</h2>'
            . '<p>문제 순서와 학습자 공개 여부·기간을 함께 저장합니다.</p></div></div>'
            . '<div class="ojp-class-problem-list">' . $rows . '</div>'
            . '<label class="ojp-field"><span class="ojp-label">추가할 문제 번호</span>'
            . '<textarea class="ojp-textarea" name="problem_ids" rows="2" '
            . 'placeholder="쉼표 또는 줄바꿈으로 구분"></textarea>'
            . '<span class="ojp-field-help">새 문제는 목록 마지막에 즉시 공개 상태로 추가됩니다.</span>'
            . '</label></section>';
    }

    private function dateTimeInputValue(?string $value): string
    {
        return $value === null || $value === ''
            ? ''
            : str_replace(' ', 'T', substr($value, 0, 16));
    }
    private function input(
        string $name,
        string $label,
        string $value,
        bool $required,
        string $type = 'text'
    ): string {
        $formatted = $type === 'datetime-local' && $value !== ''
            ? str_replace(' ', 'T', substr($value, 0, 16))
            : $value;

        return '<label class="ojp-field"><span class="ojp-label">' . $label
            . '</span><input class="ojp-input" type="' . $type . '" name="'
            . $name . '" value="' . $this->html->escape($formatted) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function textarea(string $name, string $label, string $value): string
    {
        return '<label class="ojp-field"><span class="ojp-label">' . $label
            . '</span><textarea class="ojp-textarea" name="' . $name
            . '" rows="2" placeholder="쉼표 또는 줄바꿈으로 구분">'
            . $this->html->escape($value) . '</textarea></label>';
    }

    /** @param list<int> $selectedIds */
    private function groupSelection(int $userId, array $selectedIds): string
    {
        if (! $this->groupChoices->isAvailable()) {
            $hidden = '';
            foreach ($selectedIds as $groupId) {
                $hidden .= '<input type="hidden" name="group_ids[]" value="'
                    . (int) $groupId . '">';
            }

            return $hidden . '<div class="ojp-notice ojp-notice--info">'
                . '<strong>그룹 기능이 꺼져 있습니다.</strong>'
                . '<p>기존 연결은 유지되며 그룹 기능을 켠 뒤 변경할 수 있습니다.</p></div>';
        }
        $choices = $this->groupChoices->choicesFor($userId, $selectedIds);
        $selected = array_flip($selectedIds);
        $options = '';
        foreach ($choices as $groupId => $name) {
            $options .= '<option value="' . (int) $groupId . '"'
                . (isset($selected[$groupId]) ? ' selected' : '') . '>'
                . $this->html->escape($name) . '</option>';
        }
        if ($options === '') {
            $options = '<option disabled>연결할 수 있는 그룹이 없습니다.</option>';
        }
        $size = max(8, min(count($choices), 15));

        return '<label class="ojp-field"><span class="ojp-label">연결 그룹</span>'
            . '<select class="ojp-select ojp-select--multiple" name="group_ids[]" multiple size="'
            . $size . '">'
            . $options . '</select><span class="ojp-field-help">'
            . '여러 그룹을 선택할 수 있습니다.</span></label>';
    }

    private function groupVisibilityOption(string $current): string
    {
        if (! $this->groupChoices->isAvailable() && $current !== 'group_only') {
            return '';
        }

        return $this->option('group_only', '연결 그룹', $current);
    }

    private function option(string $value, string $label, string $current): string    {
        return '<option value="' . $value . '"'
            . ($value === $current ? ' selected' : '') . '>' . $label . '</option>';
    }
    private function immutableSettingsFields(LearningClass $class): string
    {
        $instructors = implode("\n", $this->classes->instructorLogins($class->id()));
        $values = [
            'name' => $class->name(),
            'slug' => $class->slug(),
            'description' => $class->description(),
            'visibility' => $class->visibility(),
            'progress_visibility' => $class->progressVisibility(),
            'starts_at' => $class->startsAt() ?? '',
            'ends_at' => $class->endsAt() ?? '',
            'blockly_profile_key' => $class->blocklyProfileKey() ?? '',
            'instructor_user_logins' => $instructors,
        ];
        $fields = '';
        foreach ($values as $name => $value) {
            $fields .= '<input type="hidden" name="' . $name . '" value="'
                . $this->html->escape($value) . '">';
        }
        if ($class->isEnabled()) {
            $fields .= '<input type="hidden" name="enabled" value="1">';
        }
        if ($class->requiresApproval()) {
            $fields .= '<input type="hidden" name="approval_required" value="1">';
        }

        return $fields;
    }
}
