<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Ui\Icon\IconName;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class HomePageView
{
    public function __construct(
        private readonly RouteUrlProvider $urls,
        private readonly IconRenderer $icons
    ) {
    }

    public function render(HomePageState $state): string
    {
        $name = $state->identity()->displayName();
        $blocks = '';
        $primary = '';
        $secondary = '';
        $hasLearningItem = false;
        foreach ($state->blocks() as $block) {
            $section = $this->block($block);
            if ($block->key() === 'continue_learning') {
                $blocks .= $section;
                $hasLearningItem = $hasLearningItem || $block->items() !== [];
                continue;
            }
            if (in_array($block->key(), ['notices', 'staff_shortcuts'], true)) {
                $secondary .= $section;
            } else {
                $primary .= $section;
            }
            if (in_array($block->key(), ['learning_spaces', 'recent_submissions'], true)) {
                $hasLearningItem = $hasLearningItem || $block->items() !== [];
            }
        }
        if (! $hasLearningItem) {
            $primary = $this->startLearning() . $primary;
        }

        return '<header class="ojp-home-heading"><div><h1>'
            . $this->escape($name) . '님, 안녕하세요.</h1>'
            . '<p>지금 이어서 할 학습과 최근 결과를 확인하세요.</p></div>'
            . '<span class="ojp-home-date">'
            . $this->escape($state->now()->format('Y년 n월 j일'))
            . '</span></header>' . $blocks
            . '<div class="ojp-home-grid"><div class="ojp-home-column">'
            . $primary . '</div><aside class="ojp-home-column">'
            . $secondary . '</aside></div>';
    }

    private function block(HomeBlock $block): string
    {
        if ($block->presentation() === 'banner') {
            return $this->banner($block);
        }
        $heading = '<div class="ojp-section-heading"><div><h2>'
            . $this->escape($block->title()) . '</h2><p>'
            . $this->escape($block->description()) . '</p></div>'
            . $this->allLink($block->allTarget()) . '</div>';
        if ($block->failed()) {
            return '<section class="ojp-home-section">' . $heading
                . $this->fallback($block) . '</section>';
        }
        if ($block->items() === []) {
            return '<section class="ojp-home-section">' . $heading
                . '<div class="ojp-home-empty"><strong>'
                . $this->escape($block->emptyTitle()) . '</strong><p>'
                . $this->escape($block->emptyDescription()) . '</p></div></section>';
        }
        $items = match ($block->presentation()) {
            'cards' => $this->renderSpaceCards($block->items()),
            'notices' => $this->notices($block->items()),
            'shortcuts' => $this->shortcuts($block->items()),
            default => $this->list($block->items()),
        };
        $partial = $block->partial()
            ? '<p class="ojp-provider-note">일부 기능의 정보는 불러오지 못했습니다.</p>'
            : '';

        return '<section class="ojp-home-section">' . $heading
            . $partial . $items . '</section>';
    }

    private function banner(HomeBlock $block): string
    {
        $item = $block->items()[0] ?? null;
        if (! $item instanceof HomeBlockItem) {
            return $block->failed()
                ? '<section class="ojp-home-section">' . $this->fallback($block) . '</section>'
                : '';
        }

        return '<section class="ojp-continue-band"><div><span>'
            . $this->escape($item->badge()) . '</span><strong>'
            . $this->escape($item->title()) . '</strong><span>'
            . $this->escape($item->description()) . '</span></div><a class="ojp-button '
            . 'ojp-button--primary" href="' . $this->target($item->target()) . '">'
            . $this->escape($item->actionLabel()) . $this->icons->render(IconName::ArrowRight)
            . '</a></section>';
    }

    /** @param list<HomeBlockItem> $items */
    public function renderSpaceCards(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<a class="ojp-space-card" href="' . $this->target($item->target())
                . '"><div><span class="' . $this->badgeClass($item->tone()) . '">'
                . $this->escape($item->badge()) . '</span><h3>'
                . $this->escape($item->title()) . '</h3><p>'
                . $this->escape($item->description()) . '</p></div><div class="ojp-space-card__footer"><span>'
                . $this->escape($item->meta()) . '</span><b>'
                . $this->escape($item->actionLabel()) . $this->icons->render(IconName::ArrowRight)
                . '</b></div></a>';
        }

        return '<div class="ojp-space-grid">' . $html . '</div>';
    }

    /** @param list<HomeBlockItem> $items */
    private function list(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<a class="ojp-home-action-row" href="'
                . $this->target($item->target()) . '"><span><strong>'
                . $this->escape($item->title()) . '</strong><small>'
                . $this->escape($item->description()) . '</small></span><span class="ojp-home-result">'
                . ($item->badge() === '' ? '' : '<em class="' . $this->badgeClass($item->tone())
                    . '">' . $this->escape($item->badge()) . '</em>')
                . '<small>' . $this->escape($item->meta()) . '</small></span></a>';
        }

        return '<div class="ojp-home-submission-list">' . $html . '</div>';
    }

    /** @param list<HomeBlockItem> $items */
    private function notices(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<li><a href="' . $this->target($item->target()) . '">'
                . '<span class="' . $this->badgeClass($item->tone()) . '">'
                . $this->escape($item->badge()) . '</span><strong>'
                . $this->escape($item->title()) . '</strong><span>'
                . $this->escape($item->meta()) . '</span></a></li>';
        }

        return '<ul class="ojp-home-notices">' . $html . '</ul>';
    }

    /** @param list<HomeBlockItem> $items */
    private function shortcuts(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<a href="' . $this->target($item->target()) . '"><span><strong>'
                . $this->escape($item->title()) . '</strong><small>'
                . $this->escape($item->description()) . '</small></span><b>'
                . $this->escape($item->actionLabel()) . $this->icons->render(IconName::ArrowRight)
                . '</b></a>';
        }

        return '<div class="ojp-home-quick-list">' . $html . '</div>';
    }

    private function startLearning(): string
    {
        return '<section class="ojp-home-section ojp-home-start"><div>'
            . $this->icons->render(IconName::Book)
            . '<h2>아직 진행 중인 학습이 없습니다.</h2>'
            . '<p>주제와 난이도로 문제를 찾아 첫 풀이를 시작해 보세요.</p>'
            . '<a class="ojp-button ojp-button--primary" href="'
            . $this->escape($this->urls->path('problems.index')) . '">문제 찾기'
            . $this->icons->render(IconName::ArrowRight) . '</a></div></section>';
    }

    private function fallback(HomeBlock $block): string
    {
        return '<div class="ojp-provider-fallback"><div>'
            . $this->icons->render(IconName::Warning) . '<strong>'
            . $this->escape($block->emptyTitle()) . '</strong><p>'
            . $this->escape($block->emptyDescription()) . '</p><a class="ojp-button" href="'
            . $this->escape($this->urls->path('home')) . '">'
            . $this->icons->render(IconName::Refresh) . '다시 시도</a></div></div>';
    }

    private function allLink(?RouteTarget $target): string
    {
        return $target === null ? '' : '<a class="ojp-button ojp-button--small" href="'
            . $this->target($target) . '">전체 보기'
            . $this->icons->render(IconName::ArrowRight) . '</a>';
    }

    private function target(RouteTarget $target): string
    {
        return $this->escape($target->resolve($this->urls));
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
