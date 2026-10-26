<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteAccessPolicy;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteFailureResponder;
use Throwable;

final class RouteDispatcher
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly RouteHandlerRegistry $handlers,
        private readonly RouteAccessPolicy $accessPolicy,
        private readonly ErrorReporter $errorReporter,
        private readonly RouteFailureResponder $failures
    ) {
    }

    public function dispatch(HttpRequest $request): ?HttpResponse
    {
        $match = $this->matcher->match($request->method(), $request->path());
        if ($match === null) {
            return null;
        }

        $decision = $this->accessPolicy->decide($match, $request);
        if (! $decision->isAllowed()) {
            return $decision->response()
                ?? HttpResponse::forbidden('요청한 화면에 접근할 수 없습니다.');
        }

        $handler = $this->handlers->find($match->route()->key());
        if ($handler === null) {
            return $this->failures instanceof \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\NotFoundResponder
                ? $this->failures->notFound($request)
                : HttpResponse::notFound('아직 사용할 수 없는 화면입니다.');
        }

        try {
            return $handler->handle($match, $request);
        } catch (Throwable $error) {
            $this->errorReporter->report($error);

            return $this->failures->unexpected($request);
        }
    }
}
