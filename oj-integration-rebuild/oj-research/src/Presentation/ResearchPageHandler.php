<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Domain\ResearchProtocol;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use DomainException;

final class ResearchPageHandler implements RouteHandler
{
    public function __construct(private readonly ResearchService $research, private readonly CurrentAccountProvider $accounts,
        private readonly ConsoleResponder $console, private readonly PageResponder $public,
        private readonly ActionUrlProvider $urls, private readonly ActionSecurity $security, private readonly ?PilotPages $pilot = null,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter(), private readonly string $assets = '') {}

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actor = $this->accounts->current()->identity()?->userId() ?? 0;
        $isPublic = $route->route()->key() === 'research.index';
        try {
            if ($isPublic) { return $this->public->authenticated('오늘의 연구 활동', $this->assets . ($this->pilot?->hasLearner($actor) ? $this->pilot->learner($actor) : $this->learner($actor)), $request); }
            $key = $route->route()->key();
            $id = (int) ($route->parameters()['study_id'] ?? 0);
            $content = match ($key) {
                'console.research.new' => $this->pilot?->edit($actor) ?? $this->index($actor),
                'console.research.report' => $this->pilot !== null && $this->pilot->isPilot($actor,$id) ? $this->pilot->report($actor,$id) : $this->study($actor,$id),
                'console.research.rating' => $this->ratings($actor),
                'console.research.show' => $this->pilot !== null && $this->pilot->isPilot($actor,$id) ? $this->pilot->show($actor,$id,$request->queryString('tab','overview')) : $this->study($actor,$id),
                default => $this->pilot !== null && $request->queryString('legacy') !== '1' ? $this->pilot->index($actor) : $this->index($actor),
            };
            if ($request->queryString('notice') === 'saved') { $content = '<div class="ojp-notice ojp-notice--success">저장했습니다.</div>' . $content; }
            return $this->console->respond('수업 연구', $this->assets . $content, 'learning_support.research', $request);
        } catch (DomainException $error) {
            $content = '<div class="ojp-empty-state"><h1>연구 활동을 열 수 없습니다.</h1><p>' . $this->e($error->getMessage()) . '</p></div>';
            return $isPublic ? $this->public->authenticated('연구 활동', $this->assets . $content, $request, 403)
                : $this->console->respond('수업 연구', $this->assets . $content, 'learning_support.research', $request, 403);
        }
    }

    private function index(int $actor): string
    {
        $html = '<header class="ojp-page-header"><div><h1>그룹 수업 연구</h1><p>기존 학생 수준 평가를 활용하여 피드백과 독립 문제해결 결과를 비교합니다.</p></div></header>'
            . '<p><a class="ojp-button" href="/oj-console/research/rating">가림 채점함</a> <a class="ojp-button" href="/research">학생 활동</a></p>';
        $rows = '';
        foreach ($this->research->listFor($actor) as $study) {
            $rows .= '<tr><td><a href="/oj-console/research/' . $study['id'] . '">' . $this->e($study['title']) . '</a></td><td>' . $this->e(ResearchProtocol::PHASES[$study['status']] ?? $study['status']) . '</td><td>' . $study['data']['lesson'] . '</td></tr>';
        }
        $html .= $this->table(['연구', '진행 단계', '진행 차시'], $rows);
        $groups = $this->research->managedGroups($actor);
        if ($groups === []) { return $html; }
        $html .= '<section class="ojp-section"><h2>연구 만들기</h2><p>두 집단은 같은 수업·검사를 사용합니다. A는 초기 수준 반영, B는 공통 AI입니다. 초기 수준과 연구 도구는 대상자 등록 시 고정합니다.</p>' . $this->form('create');
        $html .= $this->field('연구 제목', 'title', '', true);
        $html .= '<fieldset><legend>대상 그룹</legend>';
        foreach ($groups as $id => $name) { $html .= '<label><input type="checkbox" name="groups[]" value="' . $id . '"> ' . $this->e($name) . '</label> '; }
        $html .= '</fieldset>' . $this->field('평가자 1 계정 번호', 'rater_1', '', true, 'number') . $this->field('평가자 2 계정 번호', 'rater_2', '', true, 'number')
            . '<p class="ojp-field-help">연구 운영자·참여 학생과 다른 두 평가자를 지정합니다. 두 독립 평가 점수의 평균을 검사 점수로 사용합니다.</p>';
        $html .= $this->textarea('차시별 학습 문제 번호 (한 줄에 한 차시, 여러 문제는 쉼표로 구분)', 'practice_problems', '', 4);
        $html .= $this->textarea('차시별 혼자 풀 확인 문제 (학습 문제와 같은 줄 수)', 'check_prompts', '', 4);
        foreach (['pretest' => '사전검사 문제', 'posttest' => '사후검사 문제', 'delayed' => '지연검사 문제'] as $key => $label) { $html .= $this->textarea($label . ' · 코드와 풀이 설명을 요구하는 공통 과제', $key, '', 5); }
        $html .= $this->field('지연검사 간격 (일)', 'delay_days', '14', true, 'number');
        $html .= $this->textarea('공통 AI 피드백 지침', 'instructions', ResearchProtocol::instructions(), 7);
        $html .= $this->textarea('사전 분석계획 · 대상·주요 지표·결측 처리·교사 지원 규칙', 'analysis_plan', '', 5);
        $html .= $this->field('연구 참여·자료 수집 동의 문서의 관리 번호', 'consent_reference', '', true);
        $html .= '<p>검사 루브릭: 문제 이해 20 · 절차 설계 20 · 구현 40 · 수정 및 설명 20점. 독립 검사는 아래 연구 활동에서 코드와 설명을 제출하고 두 평가자가 채점합니다.</p>' . $this->button('연구 만들기') . '</form></section>';
        return $html;
    }

    private function study(int $actor, int $id): string
    {
        $s = $this->research->managed($actor, $id);
        $p = $s['data']['protocol'];
        $participants = $this->research->participants($actor, $id);
        $roster = $this->research->roster($actor, $id);
        $names = static fn (array $member): string => $roster[$member['user_id']]['name'] ?? '계정 ' . $member['user_id'];
        $html = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">그룹 수업 연구</p><h1>' . $this->e($s['title']) . '</h1><p>' . $this->e(ResearchProtocol::PHASES[$s['status']])
            . ' · ' . $s['data']['lesson'] . ' / ' . count($p['lessons']) . '차시</p></div></header>'
            . '<p><a href="/oj-console/research">연구 목록</a> · 학생 진입 경로: <a href="/research">/research</a></p>';
        $html .= '<section class="ojp-section"><details><summary>연구 계획과 공통 과제 확인</summary><p>지연검사 간격 ' . $p['delay_days']
            . '일 · 독립 평가자 계정 ' . implode(', ', $p['raters']) . ' · 차시당 최대 3개 제출에 AI 피드백 생성</p>';
        foreach ($p['lessons'] as $index => $lesson) { $html .= '<h3>' . ($index + 1) . '차시 · 학습 문제 ' . implode(', ', $lesson['problems']) . '</h3><p>' . nl2br($this->e($lesson['check'])) . '</p>'; }
        foreach (['pretest' => '사전검사', 'posttest' => '사후검사', 'delayed' => '지연검사'] as $key => $label) { $html .= '<h3>' . $label . '</h3><p>' . nl2br($this->e($p['forms'][$key])) . '</p>'; }
        $html .= '<h3>공통 AI 지침</h3><p>' . nl2br($this->e($p['instructions'])) . '</p><h3>분석·운영 계획</h3><p>' . nl2br($this->e($p['analysis_plan']))
            . '</p><p>동의 문서: ' . $this->e($p['consent_reference']) . '</p></details></section>';
        if ($s['status'] === 'draft') {
            $html .= '<section class="ojp-section"><h2>연구 대상자와 초기 수준 저장</h2><p>동의를 확인한 학생만 선택합니다. 기존 수준 평가 결과를 그대로 저장하며, 사전검사는 그 이후에 시작합니다.</p>' . $this->form('enroll', $s);
            foreach ($roster as $member) { $html .= '<label><input type="checkbox" name="participants[]" value="' . $member['user_id'] . '"> ' . $this->e($member['name']) . '</label><br>'; }
            $html .= '<p><label><input type="checkbox" name="consent_confirmed" value="1" required> 선택 학생의 연구 참여·코드/응답 자료 수집·외부 AI 사용 범위에 대한 동의를 확인했습니다.</label></p>' . $this->button('초기 자료 고정') . '</form></section>';
        }
        $next = ['baseline' => ['pretest'], 'pretest' => ['allocation_ready'], 'allocated' => ['practice'], 'practice' => ['feedback'], 'feedback' => ['check'],
            'check' => ['between_lessons'], 'between_lessons' => [$s['data']['lesson'] < count($p['lessons']) ? 'practice' : 'posttest'],
            'posttest' => ['waiting_delay'], 'waiting_delay' => ['delayed'], 'delayed' => ['completed'], 'completed' => ['locked'], 'locked' => ['archived'], 'cancelled' => ['archived']];
        if ($s['status'] === 'allocation_ready') { $html .= $this->form('allocate', $s) . '<p>사전검사 두 평가자 채점과 미응시 사유를 완료한 뒤, 그룹·초기 수준·사전 점수를 기준으로 한 번 배정합니다.</p>' . $this->button('실험·비교 조건 배정') . '</form>'; }
        $options = $next[$s['status']] ?? [];
        if ($s['status'] === 'paused') { $options = [$s['data']['resume_phase']]; }
        elseif (! in_array($s['status'], ['draft', 'locked', 'archived', 'cancelled'], true)) { $options[] = 'paused'; }
        if (! in_array($s['status'], ['locked', 'archived', 'cancelled'], true)) { $options[] = 'cancelled'; }
        if ($options !== []) {
            $html .= '<section class="ojp-section"><h2>다음 단계</h2>' . $this->form('transition', $s) . '<label>진행 단계 <select class="ojp-select" name="next_phase">';
            foreach ($options as $phase) { $html .= '<option value="' . $phase . '">' . $this->e(ResearchProtocol::PHASES[$phase]) . '</option>'; }
            $html .= '</select></label>' . $this->field('중단·재개 사유', 'reason') . $this->button('단계 적용') . '</form><p>첫 풀이·독립 검사·중단 중에는 학생의 제출 AI가 제한됩니다. 모든 연결 그룹이 같은 단계를 진행합니다.</p></section>';
        }
        $rows = '';
        foreach ($participants as $member) {
            $level = ['beginner' => '초급', 'intermediate' => '중급', 'advanced' => '고급'][$member['baseline']['level'] ?? ''] ?? '미판정';
            $rows .= '<tr><td>' . $this->e($names($member)) . '</td><td>' . $this->e($member['pseudonym']) . '</td><td>' . $this->e($member['arm'] ?? '미배정') . '</td><td>' . $level . '</td><td>' . ($member['consent'] === 'granted' ? '동의' : '철회') . '</td>';
            foreach (['pretest', 'posttest', 'delayed'] as $assessment) {
                $outcome = $this->research->outcome($s, $member, $assessment);
                $rows .= '<td>' . ($outcome['score'] !== null ? (string) $outcome['score'] : $this->e($outcome['reason'] ?? '대기')) . '</td>';
            }
            $rows .= '</tr>';
        }
        $html .= '<section class="ojp-section"><h2>연구 원장</h2><p>학생 이름은 연구 운영자에게만 표시합니다. 원배정과 초기 수준은 고정되며 미응시는 0점과 구분합니다.</p>' . $this->table(['학생', '연구 번호', '원배정', '초기 수준', '자료 동의', '사전', '사후', '지연'], $rows) . '</section>';
        $samples = $this->research->feedbackSamples($actor, $id);
        if ($samples !== []) {
            $html .= '<section class="ojp-section"><h2>생성된 피드백 확인</h2><p>최근 생성 응답 20개입니다. 수준에 맞는 도움인지, 정답을 과도하게 제공하지 않는지 운영자가 확인합니다. 생성 기록은 학생이 읽었다는 뜻이 아닙니다.</p>';
            foreach ($samples as $sample) {
                $html .= '<details><summary>' . $this->e($sample['participant']) . ' · ' . $sample['lesson'] . '차시 · 원배정 ' . $this->e($sample['arm'] ?? '')
                    . ' · ' . ($sample['policy'] === 'level_adaptive' ? '수준 반영' : '공통') . '</summary><p>'
                    . $this->e($this->dates->format((string) $sample['at'])) . '</p>';
                $content = $sample['content'];
                $html .= '<p>' . nl2br($this->e($content['result_understanding'] ?? '')) . '</p><ol>';
                foreach ($content['step_hints'] ?? [] as $hint) { $html .= '<li>' . $this->e($hint) . '</li>'; }
                $html .= '</ol><p>' . nl2br($this->e($content['review_question'] ?? '')) . '</p></details>';
            }
            $html .= '</section>';
        }
        if ($participants !== []) {
            $choices = '<label>학생 <select class="ojp-select" name="participant_id">';
            foreach ($participants as $member) { $choices .= '<option value="' . $member['id'] . '">' . $this->e($names($member) . ' · ' . $member['pseudonym']) . '</option>'; }
            $choices .= '</select></label>';
            if (! in_array($s['status'], ['locked', 'archived', 'cancelled'], true)) {
                $html .= '<section class="ojp-section"><h2>미응시 사유</h2>' . $this->form('missing', $s) . $choices
                    . '<label>검사 <select class="ojp-select" name="assessment"><option value="pretest">사전검사</option><option value="posttest">사후검사</option><option value="delayed">지연검사</option></select></label>'
                    . '<label>미응시 사유 <select class="ojp-select" name="reason"><option value="absent">결석</option><option value="system_failure">시스템 장애</option><option value="not_submitted">미제출</option><option value="withdrawn">참여 철회</option></select></label>' . $this->button('결측 사유 기록') . '</form></section>';
            }
            $html .= '<section class="ojp-section"><h2>연구자료 사용 철회</h2><p>원배정 기록을 유지하고 향후 연구 수집과 내보내기에서 제외합니다. 보관 자료 삭제는 기관의 정해진 처리 절차를 따릅니다.</p>'
                . $this->form('withdraw', $s) . $choices . $this->field('처리 사유', 'reason', '', true) . $this->button('자료 사용 철회 기록') . '</form></section>';
            $html .= '<section class="ojp-section"><h2>가명 연구자료</h2><p>CSV는 결과표, JSON은 프로토콜·피드백 노출·채점 기록을 포함합니다. 학생 실명과 답안 코드 전문은 포함하지 않습니다.</p>'
                . $this->form('export', $s) . '<label>파일 형식 <select class="ojp-select" name="format"><option value="csv">결과표 CSV</option><option value="json">연구자료 JSON</option></select></label>' . $this->button('내려받기') . '</form></section>';
        }
        return $html;
    }

    private function learner(int $actor): string
    {
        $data = $this->research->learner($actor);
        if ($data === null) { return '<div class="ojp-empty-state"><h1>오늘의 연구 활동</h1><p>현재 배정된 연구 활동이 없습니다.</p></div>'; }
        $html = '<header class="ojp-page-header"><div><h1>' . $this->e($data['title']) . '</h1><p>' . $this->e(ResearchProtocol::PHASES[$data['phase']]) . '</p></div></header>';
        foreach ($data['problems'] as $id) { $html .= '<p><a class="ojp-button ojp-button--primary" href="/problems/' . $id . '">학습 문제 ' . $id . ' 풀기</a></p>'; }
        if ($data['assessment'] === null) { return $html . '<p>선생님의 안내에 따라 활동을 진행하세요.</p>'; }
        $html .= '<section class="ojp-section"><h2>혼자 해결하기</h2><p>' . nl2br($this->e($data['prompt'])) . '</p><p>AI 도움 없이 작성하세요. 코드와 설명에 이름·학번을 넣지 마세요.</p>';
        if ($data['submitted']) { return $html . '<p><strong>최종 답안을 제출했습니다.</strong></p></section>'; }
        $html .= $this->form('answer', ['id' => $data['study_id'], 'revision' => $data['revision']])
            . $this->textarea('코드', 'code', '', 16) . $this->textarea('문제 이해 · 해결 절차 · 오류 수정 및 설명', 'explanation', '', 7)
            . '<p>제출 후 수정할 수 없습니다. 작성한 내용을 확인한 뒤 제출하세요.</p>' . $this->button('최종 답안 제출') . '</form></section>';
        return $html;
    }

    private function ratings(int $actor): string
    {
        $html = '<header class="ojp-page-header"><div><h1>독립 채점함</h1><p>학생 정보와 조건을 보지 않고 공통 루브릭으로 채점합니다. 다른 평가자의 점수는 표시하지 않습니다.</p></div></header>';
        $tasks = $this->research->ratingTasks($actor);
        if ($tasks === []) { return $html . '<p>채점할 답안이 없습니다.</p>'; }
        foreach (array_slice($tasks, 0, 30) as $task) {
            $html .= '<section class="ojp-section"><h2>답안 ' . $this->e($task['blind_id']) . '</h2><p>' . nl2br($this->e($task['prompt'])) . '</p><pre class="ojp-code-block">' . $this->e($task['code']) . '</pre><p>' . nl2br($this->e($task['explanation'])) . '</p>'
                . $this->form('rate', ['id' => $task['study_id'], 'revision' => 0]) . '<input type="hidden" name="blind_id" value="' . $this->e($task['blind_id']) . '">';
            $labels = ['understanding' => '문제 이해', 'design' => '해결 절차', 'implementation' => '코드 구현', 'explanation' => '수정 및 설명'];
            $html .= '<div class="ojp-form-grid">';
            foreach (ResearchProtocol::RUBRIC as $key => $max) { $html .= '<label>' . $labels[$key] . ' (0~' . $max . ') <input class="ojp-input" type="number" name="' . $key . '" min="0" max="' . $max . '" step="0.5" required></label>'; }
            $html .= '</div>';
            $html .= '<p>독립 점수 저장 후 다시 수정할 수 없습니다.</p>' . $this->button('독립 점수 저장') . '</form></section>';
        }
        return $html;
    }

    private function form(string $operation, ?array $study = null): string
    {
        return '<form class="ojp-form-stack" method="post" action="' . $this->e($this->urls->actionUrl()) . '">' . $this->security->hiddenFields('oj_research_command')
            . '<input type="hidden" name="operation" value="' . $this->e($operation) . '">'
            . ($study !== null ? '<input type="hidden" name="study_id" value="' . (int) $study['id'] . '"><input type="hidden" name="revision" value="' . (int) $study['revision'] . '">' : '');
    }
    private function field(string $label, string $name, string $value = '', bool $required = false, string $type = 'text'): string
    {
        return '<p><label>' . $this->e($label) . '<br><input class="ojp-input" type="' . $type . '" name="' . $name . '" value="' . $this->e($value) . '"' . ($required ? ' required' : '') . '></label></p>';
    }
    private function textarea(string $label, string $name, string $value, int $rows): string
    {
        return '<p><label>' . $this->e($label) . '<br><textarea class="ojp-textarea" name="' . $name . '" rows="' . $rows . '" required>' . $this->e($value) . '</textarea></label></p>';
    }
    private function table(array $headings, string $rows): string
    {
        return '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>' . implode('', array_map(fn ($h) => '<th>' . $this->e($h) . '</th>', $headings))
            . '</tr></thead><tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="' . count($headings) . '">표시할 자료가 없습니다.</td></tr>') . '</tbody></table></div>';
    }
    private function button(string $label): string { return '<button class="ojp-button ojp-button--primary" type="submit">' . $this->e($label) . '</button>'; }
    private function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
