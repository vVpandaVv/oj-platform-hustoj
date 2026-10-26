<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use Throwable;

final class ActionDispatcher
{
    /**
     * @param DefinitionRegistry<ActionDefinition> $definitions
     */
    public function __construct(
        private readonly ActionHandlerRegistry $handlers,
        private readonly DefinitionRegistry $definitions,
        private readonly SystemOperationPolicy $operations,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    public function dispatch(string $actionKey, HttpRequest $request): HttpResponse
    {
        if ($request->method() !== 'POST') {
            return HttpResponse::html('허용되지 않은 요청 방식입니다.', 405);
        }
        $definition = $this->definitions->find($actionKey);
        $handler = $this->handlers->find($actionKey);
        if (! $definition instanceof ActionDefinition || $handler === null) {
            return HttpResponse::notFound('등록되지 않은 작업입니다.');
        }
        if (! $this->operations->allowsAction(
            $definition->owner()->value(),
            $actionKey
        )) {
            return HttpResponse::html(
                '데이터 이전을 위해 이 기능의 쓰기가 잠겨 있습니다.',
                423
            );
        }
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            $this->errorReporter->report($error);

            return HttpResponse::redirect('/login');
        }
    }
}
