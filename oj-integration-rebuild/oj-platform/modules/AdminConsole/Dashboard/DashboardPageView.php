<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Ui\Icon\IconName;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;

final class DashboardPageView
{
    public function __construct(
        private readonly RouteUrlProvider $urls,
        private readonly IconRenderer $icons
    ) {
    }

    public function render(DashboardPageState $state): string
    {
        $grouped = ['tasks' => [], 'jobs' => [], 'alerts' => [], 'shortcuts' => []];
        foreach ($state->blocks() as $block) {
            $grouped[$block->kind()][] = $block;
        }
        $taskCount = $this->count($grouped['tasks']);
        $attentionCount = $this->attentionCount($grouped['jobs']);
        $shortcutCount = $this->countItems($grouped['shortcuts']);
        $alertCount = $this->countItems($grouped['alerts']);
        $topAlert = $this->firstItem($grouped['alerts']);

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . ($state->approvalOnly() ? '가입 승인 범위' : '운영')
            . '</p><h1>대시보드</h1><p>내가 확인할 운영 업무와 최근 작업을 봅니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--info">'
            . $this->escape($state->roleLabel()) . '</span></header>';
        if ($state->approvalOnly()) {
            $content .= '<div class="ojp-notice ojp-notice--info"><strong>'
                . '가입 승인에 필요한 정보만 표시합니다.</strong><p>'
                . '역할·비밀번호·제출·학습 기록은 승인 업무에서 제공하지 않습니다.</p></div>';
        }
        if ($topAlert instanceof DashboardItem) {
            $content .= '<a class="ojp-dashboard-alert ojp-dashboard-alert--'
                . $this->escape($topAlert->tone()) . '" href="'
                . $this->target($topAlert) . '">'
                . $this->icons->render(IconName::Warning) . '<span><strong>'
                . $this->escape($topAlert->title()) . '</strong><small>'
                . $this->escape($topAlert->description()) . '</small></span><b>시스템 상태'
                . $this->icons->render(IconName::ArrowRight) . '</b></a>';
        }
        $content .= '<div class="ojp-dashboard-metrics">'
            . $this->metric('내 할 일', $taskCount, '현재 권한 범위')
            . $this->metric('확인 필요 작업', $attentionCount, '실패·확인 필요')
            . $this->metric('담당 바로가기', $shortcutCount, '학습 공간 포함')
            . $this->metric('운영 알림', $alertCount, '조회 권한 적용')
            . '</div><div class="ojp-dashboard-layout"><div>'
            . $this->section('내 할 일', '원본 화면에서 처리할 항목만 표시합니다.', $grouped['tasks'])
            . $this->section('최근 작업', '현재 조회 권한이 있는 작업 최대 8건입니다.', $grouped['jobs'])
            . '</div><aside>'
            . $this->section('담당 바로가기', '사용할 수 있는 관리 메뉴와 담당 공간입니다.', $grouped['shortcuts'])
            . $this->section('운영 알림', '상세 진단은 시스템 상태에서 확인합니다.', $grouped['alerts'])
            . '</aside></div>';

        return $content;
    }

    /** @param list<DashboardBlock> $blocks */
    private function section(string $title, string $description, array $blocks): string
    {
        $items = '';
        $hasItems = false;
        foreach ($blocks as $block) {
            if ($block->failed()) {
                $items .= $this->fallback($block);
                continue;
            }
            foreach ($block->items() as $item) {
                $hasItems = true;
                $items .= match ($block->kind()) {
                    'jobs' => $this->job($item),
                    'alerts' => $this->alert($item),
                    'shortcuts' => $this->shortcut($item),
                    default => $this->task($item),
                };
            }
        }
        if (! $hasItems && $items === '') {
            $items = '<div class="ojp-dashboard-empty">현재 표시할 항목이 없습니다.</div>';
        }

        return '<section class="ojp-dashboard-section"><div class="ojp-section-heading">'
            . '<div><h2>' . $this->escape($title) . '</h2><p>'
            . $this->escape($description) . '</p></div></div><div class="ojp-dashboard-block-list">'
            . $items . '</div></section>';
    }

    private function task(DashboardItem $item): string
    {
        return '<a class="ojp-task-row" href="' . $this->target($item) . '">'
            . '<span class="ojp-task-count">' . (int) ($item->count() ?? 0) . '</span>'
            . '<span class="ojp-task-main"><strong>' . $this->escape($item->title())
            . '</strong><small>' . $this->escape($item->description())
            . '</small></span><b>확인' . $this->icons->render(IconName::ArrowRight)
            . '</b></a>';
    }

    private function job(DashboardItem $item): string
    {
        $progress = $item->progress();
        $progressHtml = $progress === null ? '' : '<progress class="ojp-progress" '
            . 'max="100" value="' . $progress . '">'
            . $progress . '%</progress>';

        return '<a class="ojp-job-row" href="' . $this->target($item) . '"><span>'
            . '<strong>' . $this->escape($item->title()) . '</strong><small>'
            . $this->escape($item->description()) . '</small></span><span class="ojp-job-progress">'
            . $progressHtml . '<small>' . $this->escape($item->meta()) . '</small></span>'
            . '<em class="' . $this->badgeClass($item->tone()) . '">'
            . $this->escape($item->badge()) . '</em></a>';
    }

    private function alert(DashboardItem $item): string
    {
        return '<a class="ojp-dashboard-alert ojp-dashboard-alert--'
            . $this->escape($item->tone()) . '" href="' . $this->target($item) . '">'
            . $this->icons->render(IconName::Warning) . '<span><strong>'
            . $this->escape($item->title()) . '</strong><small>'
            . $this->escape($item->description()) . '</small></span>'
            . $this->icons->render(IconName::ArrowRight) . '</a>';
    }

    private function shortcut(DashboardItem $item): string
    {
        return '<a class="ojp-quick-row" href="' . $this->target($item) . '"><span>'
            . '<strong>' . $this->escape($item->title()) . '</strong><small>'
            . $this->escape($item->description()) . '</small></span><b>'
            . $this->icons->render(IconName::ArrowRight) . '</b></a>';
    }

    private function fallback(DashboardBlock $block): string
    {
        return '<div class="ojp-provider-fallback"><div>'
            . $this->icons->render(IconName::Warning) . '<strong>'
            . $this->escape($block->title()) . '을 불러오지 못했습니다.</strong><p>'
            . '이 영역만 실패 상태로 격리했습니다.</p><a class="ojp-button" href="'
            . $this->escape($this->urls->path('console.dashboard')) . '">'
            . $this->icons->render(IconName::Refresh) . '다시 시도</a></div></div>';
    }

    private function metric(string $label, int $value, string $description): string
    {
        return '<div><span>' . $this->escape($label) . '</span><strong>'
            . $value . '</strong><small>' . $this->escape($description) . '</small></div>';
    }

    /** @param list<DashboardBlock> $blocks */
    private function count(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            foreach ($block->items() as $item) {
                $count += $item->count() ?? 0;
            }
        }

        return $count;
    }

    /** @param list<DashboardBlock> $blocks */
    private function attentionCount(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            foreach ($block->items() as $item) {
                if (in_array($item->tone(), ['warning', 'danger'], true)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** @param list<DashboardBlock> $blocks */
    private function countItems(array $blocks, string $keyPrefix = ''): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            if ($keyPrefix !== '' && ! str_starts_with($block->key(), $keyPrefix)) {
                continue;
            }
            $count += count($block->items());
        }

        return $count;
    }

    /** @param list<DashboardBlock> $blocks */
    private function firstItem(array $blocks): ?DashboardItem
    {
        foreach ($blocks as $block) {
            foreach ($block->items() as $item) {
                return $item;
            }
        }

        return null;
    }

    private function target(DashboardItem $item): string
    {
        return $this->escape($item->target()->resolve($this->urls));
    }

    private function badgeClass(string $tone): string
    {
        $suffix = match ($tone) {
            'info' => '--info',
            'success' => '--success',
            'warning' => '--warning',
            'danger' => '--danger',
            default => '',
        };

        return $suffix === ''
            ? 'ojp-status-badge'
            : 'ojp-status-badge ojp-status-badge' . $suffix;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
