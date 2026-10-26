<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Http;

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Security\Addon_Capabilities;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

final class ArtifactDownload
{
    public const ACTION = 'oj_ml_playgrounds_artifact_download';

    private const NONCE_ACTION = 'oj_ml_playgrounds_download';
    private const CHUNK_BYTES = 1048576;
    private const MIME = 'application/vnd.ojml.mlpx+zip';

    public function __construct(
        private readonly Repository $repository,
        private readonly AssignmentService $assignments,
        private readonly PrivateArtifactStore $storage,
        private readonly ArtifactIntegrityVerifier $integrity
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public static function canDownloadStatus(string $status): bool
    {
        return in_array($status, [
            AttemptStatus::ACCEPTED,
            AttemptStatus::UNDER_REVIEW,
            AttemptStatus::GRADED,
        ], true);
    }

    /** @return array{url:string,action:string,attempt:string,nonce:string}|null */
    public static function form(string $attemptUuid, string $status): ?array
    {
        $attemptUuid = strtolower($attemptUuid);
        if (!self::validUuid($attemptUuid)
            || !self::canDownloadStatus($status)
            || !self::currentActorMayDownload()
        ) {
            return null;
        }
        return [
            'url' => admin_url('admin-post.php'),
            'action' => self::ACTION,
            'attempt' => $attemptUuid,
            'nonce' => wp_create_nonce(self::nonceAction($attemptUuid)),
        ];
    }

    public function handle(): void
    {
        try {
            $actorId = get_current_user_id();
            if ($actorId < 1) {
                throw new Problem('OJ_ML_AUTHENTICATION_REQUIRED', '로그인이 필요합니다.', 401);
            }
            if (!self::currentActorMayDownload()) {
                throw new Problem('OJ_ML_CAPABILITY_REQUIRED', '제출 원본을 내려받을 권한이 없습니다.', 403);
            }

            if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
                throw new Problem('OJ_ML_DOWNLOAD_METHOD_INVALID', 'POST 방식으로만 제출 원본을 내려받을 수 있습니다.', 405);
            }
            $attemptUuid = isset($_POST['attempt']) && is_string($_POST['attempt'])
                ? strtolower((string) wp_unslash($_POST['attempt']))
                : '';
            $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
                ? (string) wp_unslash($_POST['_wpnonce'])
                : '';
            if (!self::validUuid($attemptUuid)) {
                throw new Problem('OJ_ML_ATTEMPT_ID_INVALID', '시도 식별자가 올바르지 않습니다.', 400);
            }
            if ($nonce === '' || wp_verify_nonce($nonce, self::nonceAction($attemptUuid)) === false) {
                throw new Problem('OJ_ML_DOWNLOAD_NONCE_INVALID', '다운로드 링크가 만료되었거나 올바르지 않습니다.', 403);
            }

            $attempt = $this->assignments->teacherResult($attemptUuid, $actorId, 'evaluate');
            if (!self::canDownloadStatus((string) ($attempt['status'] ?? ''))) {
                throw new Problem(
                    'OJ_ML_ARTIFACT_DOWNLOAD_NOT_READY',
                    '서버 검사를 통과한 제출 원본만 내려받을 수 있습니다.',
                    409
                );
            }
            $artifact = $this->repository->getArtifactForInspection((int) $attempt['id']);
            if (!is_array($artifact)) {
                throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '제출 파일을 찾을 수 없습니다.', 404);
            }

            $this->stream($attempt, $artifact);
        } catch (Problem $problem) {
            $this->fail($problem);
        } catch (Throwable $throwable) {
            try {
                do_action('oj_ml_playgrounds_download_exception', $throwable);
            } catch (Throwable) {
                // Diagnostics must not escape the download boundary.
            }
            $this->fail(new Problem(
                'OJ_ML_ARTIFACT_DOWNLOAD_FAILED',
                '제출 파일을 준비하는 중 서버 오류가 발생했습니다.',
                500
            ));
        }
    }

    /** @param array<string,mixed> $attempt @param array<string,mixed> $artifact */
    private function stream(array $attempt, array $artifact): void
    {
        $storageKey = is_string($artifact['storage_key'] ?? null) ? $artifact['storage_key'] : '';
        $expectedHash = is_string($artifact['sha256'] ?? null) ? strtolower($artifact['sha256']) : '';
        $expectedBytes = isset($artifact['bytes']) && is_numeric($artifact['bytes']) ? (int) $artifact['bytes'] : 0;
        if ($storageKey === '' || $expectedBytes < 1 || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new Problem('OJ_ML_ARTIFACT_RECORD_INVALID', '제출 파일 기록이 올바르지 않습니다.', 500);
        }

        $stream = $this->storage->open($storageKey);
        try {
            $this->integrity->verify($stream, $expectedBytes, $expectedHash);
            if (headers_sent()) {
                throw new Problem('OJ_ML_DOWNLOAD_HEADERS_SENT', '다운로드 응답을 시작할 수 없습니다.', 500);
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('Vary: Cookie');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
            header('Content-Type: ' . self::MIME);
            header('Content-Disposition: attachment; filename="' . $this->filename($attempt) . '"');
            header('Content-Length: ' . $expectedBytes);

            $remaining = $expectedBytes;
            while ($remaining > 0) {
                $chunk = fread($stream, min(self::CHUNK_BYTES, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
        exit;
    }

    /** @param array<string,mixed> $attempt */
    private function filename(array $attempt): string
    {
        $userId = max(0, (int) ($attempt['user_id'] ?? 0));
        $attemptNo = max(0, (int) ($attempt['attempt_no'] ?? 0));
        return 'student-' . $userId . '-attempt-' . $attemptNo . '.mlpx';
    }

    public static function currentActorMayDownload(): bool
    {
        foreach ([Addon_Capabilities::REVIEW, Addon_Capabilities::MANAGE] as $declaredCapability) {
            $capability = Addon_Capabilities::wordpress($declaredCapability);
            if ($capability !== '' && current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }

    private static function validUuid(string $attemptUuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $attemptUuid) === 1;
    }

    private static function nonceAction(string $attemptUuid): string
    {
        return self::NONCE_ACTION . ':' . $attemptUuid;
    }

    private function fail(Problem $problem): void
    {
        wp_die(
            esc_html($problem->getMessage()),
            '제출 파일 다운로드 실패',
            ['response' => $problem->httpStatus()]
        );
        exit;
    }
}
