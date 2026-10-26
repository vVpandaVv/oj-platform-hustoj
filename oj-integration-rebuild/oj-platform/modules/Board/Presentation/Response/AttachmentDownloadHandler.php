<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Response;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\AttachmentService;
use Throwable;

final class AttachmentDownloadHandler implements RouteHandler
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly AttachmentService $attachments
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($request);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $download = $this->attachments->download(
                $userId,
                (int) ($route->parameters()['attachment_id'] ?? 0)
            );
            $attachment = $download->attachment();
            $extension = strtolower(pathinfo(
                $attachment->displayName(),
                PATHINFO_EXTENSION
            ));
            $fallback = 'community-attachment-' . $attachment->id()
                . ($extension === '' ? '' : '.' . $extension);
            $encoded = rawurlencode(str_replace(
                ["\r", "\n"],
                '',
                $attachment->displayName()
            ));
            $contents = $download->contents();

            return new HttpResponse(
                200,
                [
                    'Content-Type' => $attachment->mimeType(),
                    'Content-Disposition' => 'attachment; filename="' . $fallback
                        . '"; filename*=UTF-8\'\'' . $encoded,
                    'Content-Length' => (string) strlen($contents),
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                    'Content-Security-Policy' => "default-src 'none'",
                ],
                $contents
            );
        } catch (Throwable) {
            return new HttpResponse(
                404,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Cache-Control' => 'private, no-store',
                ],
                '첨부파일을 찾을 수 없습니다.'
            );
        }
    }
}
