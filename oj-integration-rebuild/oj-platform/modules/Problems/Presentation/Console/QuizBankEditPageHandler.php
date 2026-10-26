<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use DomainException;

final class QuizBankEditPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizBankService $bank,
        private readonly QuizEditForm $form
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $bankId = (int) ($route->parameters()['bank_id'] ?? 0);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $item = $this->bank->find($userId, $bankId);
        } catch (DomainException) {
            return $this->pages->respond(
                '문항 은행 편집',
                '<div class="ojp-empty-state"><h1>문항을 편집할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/quiz-bank">문항 은행</a></div>',
                'problems.quiz_bank',
                $request,
                404
            );
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>문항 은행 편집</h1><p>이 문항이 사용 중인 퀴즈 '
            . $this->bank->usageCount($userId, $bankId) . '건에는 영향을 주지 않습니다.'
            . '</p></div></header>'
            . $this->form->renderBankItem(
                $item->question(),
                'oj_quiz_bank_save',
                $item->id()
            );

        return $this->pages->respond(
            '문항 은행 편집',
            $content,
            'problems.quiz_bank',
            $request
        );
    }
}
