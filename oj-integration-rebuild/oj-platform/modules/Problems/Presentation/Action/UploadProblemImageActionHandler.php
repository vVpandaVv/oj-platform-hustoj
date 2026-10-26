<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemImageService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class UploadProblemImageActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_upload_image';

    public function __construct(
        private readonly ProblemImageService $images,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $file = $request->file('image');
            if ($file === null || ! $file->isSuccessful()) {
                throw new DomainException('올릴 이미지를 선택해 주세요.');
            }
            $path = $file->temporaryPath();
            $bytes = is_file($path)
                ? file_get_contents($path, false, null, 0, ProblemImage::MAX_BYTES + 1)
                : false;
            if (! is_string($bytes)) {
                throw new DomainException('업로드한 이미지를 읽지 못했습니다.');
            }
            $problemIdValue = $request->bodyInt('problem_id');
            $result = $this->images->upload(
                $actor->userId(),
                $problemIdValue > 0 ? new ProblemId($problemIdValue) : null,
                $bytes
            );

            return $this->json(200, ['ok' => true] + $result);
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->json(422, ['ok' => false, 'message' => $error->getMessage()]);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->json(
                500,
                ['ok' => false, 'message' => '이미지를 저장하지 못했습니다. 잠시 뒤 다시 시도해 주세요.']
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        try {
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            $encoded = '{"ok":false,"message":"응답을 만들지 못했습니다."}';
            $status = 500;
        }

        return new HttpResponse(
            $status,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $encoded
        );
    }
}
