<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;

final class FeatureSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly FeatureService $features,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->features->snapshot();
        $rows = '';
        foreach ($this->features->definitions() as $definition) {
            $dependencies = $definition->dependencies() === []
                ? '없음'
                : implode(', ', $definition->dependencies());
            $rows .= '<div class="ojp-setting-row"><div><strong>'
                . OperationsHtml::escape($definition->label())
                . '</strong><small><code>'
                . OperationsHtml::escape($definition->key())
                . '</code> · 의존: ' . OperationsHtml::escape($dependencies)
                . '</small></div><label class="ojp-switch"><input type="checkbox"'
                . ' name="features[' . OperationsHtml::escape($definition->key())
                . ']" value="1"'
                . OperationsHtml::checked($this->features->isEnabled($definition->key()))
                . ($definition->isRequired() ? ' disabled' : '')
                . '><span>' . ($definition->isRequired() ? '필수' : '사용')
                . '</span></label>'
                . ($definition->isRequired()
                    ? '<input type="hidden" name="features['
                        . OperationsHtml::escape($definition->key())
                        . ']" value="1">'
                    : '')
                . '</div>';
        }
        $content = OperationsHtml::header(
            '운영',
            '기능 관리',
            '기능과 의존 관계를 한 번에 검증하고 저장합니다.'
        )
            . '<section class="ojp-section"><form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_feature_flags')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-settings-list">'
            . $rows . '</div><div class="ojp-command-group">'
            . '<button class="ojp-button ojp-button--primary" type="submit">기능 설정 저장</button>'
            . '</div></form></section>';

        return $this->pages->respond(
            '기능 관리',
            $content,
            'operations.features',
            $request
        );
    }
}
