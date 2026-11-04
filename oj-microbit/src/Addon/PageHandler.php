<?php
declare(strict_types=1);
namespace OjMicrobit\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OjMicrobit\Support\Settings;

final class PageHandler implements AddonPageHandlerV1
{
    public function __construct(private readonly string $mode, private readonly string $section = 'problems', private readonly string $initialView = '')
    {
        if (!in_array($mode, ['teacher', 'student'], true) || !in_array($section, ['problems', 'lessons'], true)
            || !in_array($initialView, ['', 'new'], true) || ($initialView === 'new' && ($mode !== 'teacher' || $section !== 'problems'))) {
            throw new \InvalidArgumentException('마이크로비트 화면 구분을 확인해 주세요.');
        }
    }
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $teacher = $this->mode === 'teacher'; $lessons = $this->section === 'lessons';
        $title = $lessons ? ($teacher ? '마이크로비트 수업 적용·결과' : '마이크로비트 수업') : ($teacher ? '마이크로비트 문제 관리' : '마이크로비트 문제');
        if ($this->initialView === 'new') { $title = '마이크로비트 문제 생성'; }
        $routes = method_exists($request, 'navigationUrls') ? $request->navigationUrls() : [];
        $problemPage = $teacher ? 'addon.oj_microbit.teacher' : 'addon.oj_microbit.learn';
        $lessonPage = $teacher ? 'addon.oj_microbit.teacher_lessons' : 'addon.oj_microbit.lessons';
        $native = true;
        foreach ([$problemPage, $lessonPage] as $route) {
            if (!is_string($routes[$route] ?? null) || $routes[$route] === '') { $native = false; break; }
        }
        $config = htmlspecialchars((string) wp_json_encode(['mode' => $this->mode, 'initialSection' => $this->section, 'initialView' => $this->initialView,
            'nativeNavigation' => $native, 'shellNavigation' => $native, 'compactEditor' => true, 'problemMenuUrl' => $routes[$problemPage] ?? '', 'lessonMenuUrl' => $routes[$lessonPage] ?? '',
            'problemPageUrl' => $routes[$problemPage] ?? '', 'lessonPageUrl' => $routes[$lessonPage] ?? '',
            'createPageUrl' => $teacher ? ($routes['addon.oj_microbit.teacher_new'] ?? '') : '',
            'restUrl' => rest_url('oj-microbit/v1/'), 'nonce' => wp_create_nonce('wp_rest'), 'userId' => get_current_user_id(), 'isAdmin' => current_user_can('manage_options'),
            'runnerReady' => Settings::configured(), 'editorUrl' => 'https://makecode.microbit.org/']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = $lessons
            ? ($teacher ? '이 수업에 공개 문제를 적용하고 학생별 제출 결과를 확인합니다.' : '수업에서 문제를 열고 해당 수업의 제출 결과를 확인합니다.')
            : ($teacher ? '안내 → 장치 → 예시 → 시작 코드 순서로 설정하세요.' : '공개된 문제를 선택해 MakeCode로 풀고 채점합니다.');
        return AddonPageResponseV1::page($title, '<div class="ojmicrobit" data-oj-microbit data-config="' . $config . '">'
            . '<header class="ojp-page-header ojmicrobit-page-header"><div><h1>' . $title . '</h1><p>' . $description
            . '</p></div></header><p class="ojmicrobit-status" data-microbit-status role="status" aria-live="polite">실습 화면을 불러오고 있습니다.</p>'
            . '<div data-microbit-content></div>'
            . '<noscript><p>JavaScript를 켜면 MakeCode 편집기와 실습 화면을 사용할 수 있습니다.</p></noscript></div>', 'wide', true);
    }
}
