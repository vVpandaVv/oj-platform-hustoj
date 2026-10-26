<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator;
use CodingPanda\OjPlatform\Kernel\Setup\SetupMode;
use CodingPanda\OjPlatform\Kernel\Setup\SetupScreen;
use CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetEnqueuer;
use Throwable;

final class WordPressSetupScreen implements SetupScreen
{
    private const PAGE_SLUG = 'oj-platform-setup';

    public function __construct(
        private readonly SetupCoordinator $coordinator,
        private readonly UiAssetEnqueuer $assets,
        private readonly ErrorReporter $errorReporter,
        private readonly WordPressRewriteManager $rewrites,
        private readonly WordPressRouteTransport $routes,
        private readonly JudgeConnectionSettingsProvider $judgeSettings
    ) {
    }

    public function registerPage(): void
    {
        if (! function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            'OJ Platform 설치',
            'OJ Platform 설치',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-admin-tools'
        );
    }

    public function renderPendingNotice(): void
    {
        if (! $this->canManage()) {
            return;
        }

        $url = $this->setupUrl();
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>OJ Platform 설치를 완료해 주세요.</strong> ';
        echo '설치가 끝날 때까지 사용자 화면은 열리지 않습니다. ';
        echo '<a class="button button-primary" href="' . $this->escapeUrl($url) . '">';
        echo '설정 시작</a></p></div>';
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (
            $hookSuffix !== 'toplevel_page_' . self::PAGE_SLUG
        ) {
            return;
        }

        $this->assets->enqueue();
    }

    public function handleAction(): void
    {
        if (! $this->canManage()) {
            $this->stop('설치 권한이 없습니다.');
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer('oj_platform_setup');
        }

        try {
            $result = $this->coordinator->run();
            $snapshot = $result->snapshot();
            $status = match (true) {
                $snapshot->status() === 'migration_ready' => 'migration',
                $snapshot->mode() === SetupMode::AlreadyInstalled => 'complete',
                $result->pendingTables() === [] => 'prepared',
                default => 'waiting',
            };
            if (in_array($status, ['complete', 'migration'], true)) {
                try {
                    $this->rewrites->synchronizeNow();
                } catch (Throwable $error) {
                    $this->errorReporter->report($error);
                }
            }

            $this->redirect($status);
        } catch (Throwable $error) {
            $this->errorReporter->report($error);
            $this->redirect('failed');
        }
    }

    public function render(): void
    {
        if (! $this->canManage()) {
            $this->stop('설치 권한이 없습니다.');
        }

        $snapshot = $this->coordinator->inspect();
        $notice = isset($_GET['setup']) ? (string) $_GET['setup'] : '';

        echo '<div class="wrap ojp-shell">';
        echo '<header class="ojp-page-header">';
        echo '<div><p class="ojp-eyebrow">초기 구성</p><h1>OJ Platform 설치</h1>';
        echo '<p>플랫폼 데이터와 연결 상태를 확인한 뒤 안전하게 설치를 계속합니다.</p></div>';
        echo '<span class="ojp-status">' . $this->escape($snapshot->mode()->label()) . '</span>';
        echo '</header>';

        $this->renderNotice($notice, $snapshot);
        if (function_exists('current_user_can') && current_user_can('install_plugins')) {
            echo '<section class="ojp-section"><h2>서버 준비</h2><p>AI·ML을 사용할 경우 활성화 전에 저장소를 준비하세요.</p>'
                . '<a class="ojp-button" target="_blank" rel="noopener" href="'
                . $this->escapeUrl(admin_url('admin.php?page=oj-server-install')) . '">서버 설치 열기</a></section>';
        }
        $this->renderSummary($snapshot);
        $this->renderTables($snapshot);
        $this->renderReadiness($snapshot);
        $this->renderAction($snapshot);

        echo '</div>';
    }

    private function renderNotice(string $notice, SetupSnapshot $snapshot): void
    {
        $message = match ($notice) {
            'prepared' => '현재 단계의 준비 작업을 완료했습니다.',
            'waiting' => '공통 테이블을 준비했습니다. 나머지 기능 스키마 등록을 기다립니다.',
            'complete' => $this->judgeSettings->current() === null
                ? '플랫폼 설치를 완료했습니다. Judge DB 연결 설정을 계속합니다.'
                : '설치를 완료했습니다. 운영 개요로 이동합니다.',
            'failed' => '설치를 완료하지 못했습니다. 아래 원인을 확인한 후 다시 진행해 주세요.',
            default => null,
        };

        if ($message === null) {
            return;
        }

        $tone = $notice === 'failed' ? 'danger' : 'success';
        echo '<div class="ojp-notice ojp-notice--' . $tone . '">';
        echo '<p>' . $this->escape($message) . '</p>';
        if ($notice === 'failed' && $snapshot->lastErrorMessage() !== null) {
            echo '<p><strong>실패 원인:</strong> '
                . $this->escape($snapshot->lastErrorMessage())
                . '</p>';
        }
        echo '</div>';
    }

    private function renderSummary(SetupSnapshot $snapshot): void
    {
        $ready = count($snapshot->existingTables());

        echo '<section class="ojp-section" aria-labelledby="setup-summary">';
        echo '<div class="ojp-section-heading"><div><h2 id="setup-summary">설치 상태</h2>';
        echo '<p>일반 페이지 요청에서는 구조를 변경하지 않습니다.</p></div></div>';
        echo '<dl class="ojp-metrics">';
        $this->renderMetric('설치 모드', $snapshot->mode()->label());
        $this->renderMetric('목표 테이블', (string) $snapshot->targetTableCount());
        $this->renderMetric('등록된 스키마', (string) $snapshot->registeredSchemaCount());
        $this->renderMetric('현재 준비 가능', (string) max(0, $ready));
        $this->renderMetric(
            'Judge DB 연결',
            $this->judgeSettings->current() === null ? '설정 필요' : '설정 완료'
        );
        echo '</dl></section>';
    }

    private function renderTables(SetupSnapshot $snapshot): void
    {
        echo '<section class="ojp-section" aria-labelledby="setup-tables">';
        echo '<div class="ojp-section-heading"><div><h2 id="setup-tables">테이블 확인</h2>';
        echo '<p>플랫폼 테이블을 준비한 뒤 Judge DB 연결 적용 단계에서 problem 확장 필드 5개만 없는 경우 생성합니다.</p>';
        echo '</div></div>';
        echo '<div class="ojp-table-wrap"><table class="ojp-table">';
        echo '<thead><tr><th>항목</th><th>수량</th><th>내용</th></tr></thead><tbody>';
        $this->renderTableRow('기존 데이터 발견', $snapshot->legacyTables());
        $this->renderTableRow('현재 발견', $snapshot->existingTables());
        $this->renderTableRow('충돌', $snapshot->collisions());
        $this->renderTableRow('스키마 등록 대기', $snapshot->pendingSchemas());
        $this->renderTableRow('스키마 업그레이드 대기', $snapshot->pendingUpgrades());
        echo '</tbody></table></div></section>';
    }

    private function renderAction(SetupSnapshot $snapshot): void
    {
        echo '<footer class="ojp-action-bar">';
        echo '<p>' . $this->escape($this->actionDescription($snapshot->mode())) . '</p>';

        if ($snapshot->allowsSetupExecution() && $snapshot->isRuntimeReady()) {
            $action = function_exists('admin_url')
                ? admin_url('admin-post.php')
                : 'admin-post.php';
            echo '<form method="post" action="' . $this->escape($action) . '">';
            echo '<input type="hidden" name="action" value="oj_platform_setup">';
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('oj_platform_setup');
            }
            echo '<button type="submit" class="ojp-button ojp-button--primary ojp-primary-button">'
                . $this->escape($this->actionLabel($snapshot->mode()))
                . '</button>';
            echo '</form>';
        }

        echo '</footer>';
    }

    private function renderReadiness(SetupSnapshot $snapshot): void
    {
        if ($snapshot->isRuntimeReady()) {
            return;
        }

        echo '<section class="ojp-section" aria-labelledby="setup-readiness">';
        echo '<div class="ojp-section-heading"><div><h2 id="setup-readiness">서버 준비 필요</h2>';
        echo '<p>아래 항목을 해결하기 전에는 테이블이나 역할을 변경하지 않습니다.</p></div></div>';
        echo '<ul class="ojp-list">';
        foreach ($snapshot->readinessBlockers() as $blocker) {
            echo '<li>' . $this->escape($blocker) . '</li>';
        }
        echo '</ul></section>';
    }

    private function renderMetric(string $label, string $value): void
    {
        echo '<div><dt>' . $this->escape($label) . '</dt><dd>' . $this->escape($value) . '</dd></div>';
    }

    /**
     * @param list<string> $items
     */
    private function renderTableRow(string $label, array $items): void
    {
        $details = $items === [] ? '없음' : implode(', ', $items);
        echo '<tr><th scope="row">' . $this->escape($label) . '</th>';
        echo '<td>' . count($items) . '</td>';
        echo '<td class="ojp-code-list">' . $this->escape($details) . '</td></tr>';
    }

    private function actionDescription(SetupMode $mode): string
    {
        return match ($mode) {
            SetupMode::FreshInstall => '실행하면 등록된 플랫폼 테이블과 역할을 준비하고 단계별 체크포인트를 기록합니다.',
            SetupMode::Resume => '같은 설치 ID와 계획을 확인한 뒤 완료되지 않은 단계만 계속합니다.',
            SetupMode::MigrationTarget => '기존 데이터는 자동으로 복사하거나 전환하지 않습니다. 이전 작업과 로그인에 필요한 기반만 준비합니다.',
            SetupMode::NameCollision => '동명 테이블의 소유와 구조를 확인하기 전에는 설치할 수 없습니다.',
            SetupMode::AlreadyInstalled => '설치가 완료되어 임시 설치 기능을 사용하지 않습니다.',
        };
    }

    private function actionLabel(SetupMode $mode): string
    {
        return match ($mode) {
            SetupMode::FreshInstall => '신규 설치',
            SetupMode::Resume => '설치 계속',
            SetupMode::MigrationTarget => '이전 기반 준비',
            default => '설치 계속',
        };
    }

    private function canManage(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    private function redirect(string $status): never
    {
        if ($status === 'migration') {
            $url = $this->routes->routeUrl('/oj-console/settings/migration');
        } elseif ($status === 'complete') {
            $url = $this->routes->routeUrl(
                $this->judgeSettings->current() === null
                    ? '/oj-console/settings/database' : '/oj-console'
            );
        } else {
            $url = $this->setupUrl($status);
        }

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }

        throw new \RuntimeException('설치 화면으로 이동하지 못했습니다.');
    }

    private function stop(string $message): never
    {
        if (function_exists('wp_die')) {
            wp_die($this->escape($message), 'OJ Platform');
        }

        throw new \RuntimeException($message);
    }

    private function escape(string $value): string
    {
        return function_exists('esc_html')
            ? esc_html($value)
            : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeUrl(string $value): string
    {
        return function_exists('esc_url')
            ? esc_url($value)
            : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function setupUrl(?string $status = null): string
    {
        $path = 'admin.php?page=' . self::PAGE_SLUG;
        if ($status !== null) {
            $path .= '&setup=' . rawurlencode($status);
        }

        return function_exists('admin_url') ? admin_url($path) : $path;
    }
}
