<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;

final class QuizBankNewPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly QuizEditForm $form
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>문항 은행 문항 추가</h1></div></header>'
            . $this->form->renderBankItem(null, 'oj_quiz_bank_save', null);

        return $this->pages->respond(
            '문항 은행 문항 추가',
            $content,
            'problems.quiz_bank',
            $request
        );
    }
}
