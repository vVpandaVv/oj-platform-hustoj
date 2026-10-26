<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use Throwable;

final class ProblemTestDataSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SettingsManager $settings,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html,
        private readonly SpjCompilerProfileProvider $compilerProfiles,
        private readonly SpjSandboxProfileProvider $sandboxProfiles
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $snapshot = $this->settings->read('problem_test_data', []);
        $configuration = ProblemTestDataConfiguration::from($snapshot->value());
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">문제 운영</p><h1>테스트 환경</h1>'
            . '<p>문제 테스트 파일 저장 위치와 특수 채점기 컴파일 환경을 관리합니다.</p>'
            . '</div></header>'
            . $this->status($configuration)
            . $this->rootForm($configuration, $snapshot->revision())
            . $this->spjForm($configuration, $snapshot->revision());

        return $this->pages->respond(
            '테스트 환경',
            $content,
            'operations.test_data',
            $request
        );
    }

    private function status(ProblemTestDataConfiguration $configuration): string
    {
        $root = realpath($configuration->root());
        $rootReady = is_string($root)
            && is_dir($root)
            && is_readable($root)
            && is_writable($root);
        try {
            $this->compilerProfiles->forSource('spj.c');
            $this->compilerProfiles->forSource('spj.cpp');
            $this->sandboxProfiles->current();
            $spjReady = true;
        } catch (Throwable) {
            $spjReady = false;
        }

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>현재 상태</h2><p>저장 전에 웹 서버 실행 계정 기준으로 확인합니다.</p>'
            . '</div></div><dl class="ojp-metrics">'
            . $this->metric('테스트 데이터', $rootReady ? '읽기·쓰기 가능' : '확인 필요')
            . $this->metric('SPJ 컴파일', $spjReady ? '실행 파일 준비됨' : '확인 필요')
            . '</dl></section>';
    }

    private function rootForm(
        ProblemTestDataConfiguration $configuration,
        int $revision
    ): string {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>테스트 데이터 폴더</h2>'
            . '<p>웹 서버가 관리하고 Judge가 같은 데이터를 읽는 문제별 폴더의 상위 경로입니다.</p>'
            . '</div></div><form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_problem_test_data_settings')
            . '<input type="hidden" name="settings_section" value="root">'
            . '<input type="hidden" name="expected_revision" value="' . $revision . '">'
            . $this->field('절대 경로', 'root', $configuration->root())
            . '<p class="ojp-field-help">WordPress 공개 경로 밖에 두고 웹 서버 계정에 읽기와 쓰기 권한을 부여하세요. 서버가 분리된 경우 같은 저장소가 양쪽에 보이도록 마운트해야 합니다.</p>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '데이터 폴더 저장</button></form></section>';
    }

    private function spjForm(
        ProblemTestDataConfiguration $configuration,
        int $revision
    ): string {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>특수 채점기 컴파일</h2>'
            . '<p>승인된 <code>/usr</code> 실행 파일만 사용하며 컴파일은 bwrap 안에서 수행합니다.</p>'
            . '</div></div><form class="ojp-form-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_problem_test_data_settings')
            . '<input type="hidden" name="settings_section" value="spj">'
            . '<input type="hidden" name="expected_revision" value="' . $revision . '">'
            . '<div class="ojp-form-grid">'
            . $this->field('C 컴파일러', 'c_compiler', $configuration->cCompiler())
            . $this->field('C++ 컴파일러', 'cpp_compiler', $configuration->cppCompiler())
            . $this->field('자원 제한 실행 파일', 'limit_executable', $configuration->limitExecutable())
            . $this->field('격리 실행 파일', 'sandbox_executable', $configuration->sandboxExecutable())
            . '</div><button class="ojp-button ojp-button--primary" type="submit">'
            . 'SPJ 환경 저장</button></form></section>';
    }

    private function field(string $label, string $name, string $value): string
    {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label) . '</span><input class="ojp-input" type="text"'
            . ' name="' . $this->html->escape($name) . '" value="'
            . $this->html->escape($value) . '" maxlength="1024" required></label>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div><dt>' . $this->html->escape($label) . '</dt><dd>'
            . $this->html->escape($value) . '</dd></div>';
    }
}
