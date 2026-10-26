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
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;

final class ProblemTopicsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemTopicDictionary $topics,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        if (! $this->access->canManageTopics($userId)) {
            return $this->pages->respond(
                '주제 관리',
                '<div class="ojp-empty-state"><h1>주제를 관리할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.index',
                $request,
                403
            );
        }
        $all = $this->topics->all();
        $categories = array_values(array_filter(
            $all,
            static fn (ProblemTopic $topic): bool => $topic->isCategory()
        ));
        $details = [];
        foreach ($all as $topic) {
            if (! $topic->isCategory()) {
                $details[$topic->categoryKey()][] = $topic;
            }
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제</p>'
            . '<h1>주제 관리</h1><p>분야와 세부 주제의 2단계 사전을 관리합니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--neutral">'
            . count($categories) . '개 분야 · ' . (count($all) - count($categories))
            . '개 세부 주제</span></header>'
            . '<div class="ojp-split-layout">'
            . '<section class="ojp-form-section"><h2>현재 주제</h2>'
            . $this->tree($categories, $details) . '</section>'
            . '<div class="ojp-stack">'
            . $this->categoryForm()
            . $this->detailForm($categories)
            . '</div></div>';

        return $this->pages->respond(
            '주제 관리',
            $content,
            'problems.topics',
            $request
        );
    }

    /**
     * @param list<ProblemTopic> $categories
     * @param array<string, list<ProblemTopic>> $details
     */
    private function tree(array $categories, array $details): string
    {
        $html = '<div class="ojp-topic-tree">';
        foreach ($categories as $category) {
            $html .= '<section class="ojp-topic-group"><h3>'
                . $this->html->escape($category->label())
                . '<code>' . $this->html->escape($category->key()) . '</code></h3>'
                . '<ul>';
            foreach ($details[$category->key()] ?? [] as $detail) {
                $html .= '<li><span>' . $this->html->escape($detail->label())
                    . '</span><code>' . $this->html->escape($detail->key())
                    . '</code><small>'
                    . $this->html->escape(implode(', ', $detail->aliases()))
                    . '</small></li>';
            }
            $html .= '</ul></section>';
        }

        return $html . '</div>';
    }

    private function categoryForm(): string
    {
        return '<form class="ojp-form-section" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '"><h2>새 분야</h2>'
            . $this->security->hiddenFields('oj_problem_topic_create')
            . '<input type="hidden" name="topic_kind" value="category">'
            . $this->field('topic_key', '영문 키', '예: advanced-algorithms')
            . $this->field('topic_label', '한글 이름', '예: 고급 알고리즘')
            . $this->field('topic_aliases', 'AI 입력 별칭', '쉼표로 구분')
            . '<button class="ojp-button ojp-button--primary" type="submit">분야 추가</button>'
            . '</form>';
    }

    /**
     * @param list<ProblemTopic> $categories
     */
    private function detailForm(array $categories): string
    {
        $options = '';
        foreach ($categories as $category) {
            $options .= '<option value="' . $this->html->escape($category->key()) . '">'
                . $this->html->escape($category->label()) . '</option>';
        }

        return '<form class="ojp-form-section" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '"><h2>새 세부 주제</h2>'
            . $this->security->hiddenFields('oj_problem_topic_create')
            . '<input type="hidden" name="topic_kind" value="detail">'
            . '<label class="ojp-field"><span class="ojp-label">소속 분야</span>'
            . '<select class="ojp-select" name="category_key" required>' . $options
            . '</select></label>'
            . $this->field('topic_key', '영문 키', '예: segment-tree')
            . $this->field('topic_label', '한글 이름', '예: 세그먼트 트리')
            . $this->field('topic_aliases', 'AI 입력 별칭', '쉼표로 구분')
            . '<button class="ojp-button ojp-button--primary" type="submit">세부 주제 추가</button>'
            . '</form>';
    }

    private function field(string $name, string $label, string $placeholder): string
    {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->html->escape($label)
            . '</span><input class="ojp-input" type="text" name="'
            . $this->html->escape($name) . '" placeholder="'
            . $this->html->escape($placeholder) . '" required></label>';
    }
}
