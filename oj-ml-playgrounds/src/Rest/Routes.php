<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Application\ContextService;
use OJML\Playgrounds\Application\SubmissionService;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Security\Local_Storage_Credentials;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Routes
{
    public const NAMESPACE = 'oj-ml/v1';

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly SubmissionService $submissions,
        private readonly ContextService $contexts,
        private readonly Presenter $presenter
    ) {
    }

    public function register(): void
    {
        $this->route('/session/storage-context', WP_REST_Server::READABLE, 'storageContext', 'learnerRead');
        $this->route('/assignments', WP_REST_Server::READABLE, 'learnerAssignments', 'learnerRead');
        $this->route('/assignments/(?P<id>\d+)', WP_REST_Server::READABLE, 'learnerAssignment', 'learnerRead');
        $this->route('/assignments/(?P<id>\d+)/history', WP_REST_Server::READABLE, 'learnerHistory', 'learnerRead');
        $this->route('/assignments/(?P<id>\d+)/attempts', WP_REST_Server::CREATABLE, 'createAttempt', 'learnerMutation');
        $this->route('/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/progress', WP_REST_Server::CREATABLE, 'saveProgress', 'learnerMutation');
        $this->route('/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/submit', WP_REST_Server::CREATABLE, 'submit', 'learnerMutation');
        $this->route('/attempts/(?P<uuid>[0-9A-Fa-f-]{36})', WP_REST_Server::READABLE, 'ownResult', 'learnerRead');

        $this->route('/console/contexts', WP_REST_Server::READABLE, 'teachingContexts', 'manageRead');
        register_rest_route(self::NAMESPACE, '/console/assignments', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'teacherAssignments'],
                'permission_callback' => [Permissions::class, 'manageRead'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createAssignment'],
                'permission_callback' => [Permissions::class, 'manageMutation'],
            ],
        ]);
        $this->route('/console/report-assignments', WP_REST_Server::READABLE, 'reportAssignments', 'reportRead');
        $this->route('/console/report-assignment-options', WP_REST_Server::READABLE, 'reportAssignmentOptions', 'reportRead');
        register_rest_route(self::NAMESPACE, '/console/assignments/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'teacherAssignment'],
                'permission_callback' => [Permissions::class, 'manageRead'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'updateAssignment'],
                'permission_callback' => [Permissions::class, 'manageMutation'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteAssignment'],
                'permission_callback' => [Permissions::class, 'manageMutation'],
            ],
        ]);
        $this->route('/console/assignments/(?P<id>\d+)/publish', WP_REST_Server::CREATABLE, 'publishAssignment', 'manageMutation');
        $this->route('/console/assignments/(?P<id>\d+)/duplicate', WP_REST_Server::CREATABLE, 'duplicateAssignment', 'manageMutation');
        $this->route('/console/assignments/(?P<id>\d+)/learners/(?P<user_id>\d+)/allowance', WP_REST_Server::CREATABLE, 'grantAllowance', 'manageMutation');
        $this->route('/console/assignments/(?P<id>\d+)/learners/(?P<user_id>\d+)/history', WP_REST_Server::READABLE, 'teacherHistory', 'reportRead');
        $this->route('/console/assignments/(?P<id>\d+)/results', WP_REST_Server::READABLE, 'teacherResults', 'reportRead');
        $this->route('/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})', WP_REST_Server::READABLE, 'teacherResult', 'reportRead');
        $this->route('/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/review', WP_REST_Server::CREATABLE, 'review', 'reviewMutation');
        $this->route('/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/release', WP_REST_Server::CREATABLE, 'release', 'reviewMutation');
        $this->route('/console/health', WP_REST_Server::READABLE, 'health', 'settingsRead');
    }

    public function storageContext(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'storage' => Local_Storage_Credentials::for_user($this->actor()) + [
                'mode' => 'shared',
            ],
        ]));
    }

    public function learnerAssignments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $items = array_map(
                fn (array $item): array => $this->presenter->learnerAssignmentListItem($item),
                $this->assignments->listForLearner($this->actor(), max(1, (int) $request->get_param('page')))
            );
            return $this->result(['items' => $items, 'hasMore' => $this->assignments->learnerHasMore()]);
        });
    }

    public function learnerHistory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->history($request, false);
    }

    public function teacherHistory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->history($request, true);
    }

    private function history(WP_REST_Request $request, bool $teacher): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request, $teacher): array {
            $page = $this->assignments->attemptHistory((int) $request['id'],
                $teacher ? (int) $request['user_id'] : $this->actor(), $this->actor(),
                max(1, (int) $request->get_param('page')), $teacher);
            return $this->result([
                'items' => array_map(fn (array $attempt): array => $this->presenter->attempt($attempt, $teacher), $page['items']),
                'total' => $page['total'], 'page' => $page['page'], 'perPage' => $page['per_page'],
            ]);
        });
    }

    public function grantAllowance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $lease = \OJML\Playgrounds\Ops\Lease::acquire('learner:' . (int) $request['user_id']);
            return $this->result(['allowance' => $this->assignments->grantAllowance(
                (int) $request['id'], (int) $request['user_id'], $this->actor(), $this->body($request), $this->correlationId()
            )]);
        });
    }

    public function duplicateAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result(['assignment' => $this->presenter->assignment(
            $this->assignments->duplicateAssignment((int) $request['id'], $this->actor()), true
        )], 201));
    }

    public function learnerAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'assignment' => $this->presenter->assignment(
                $this->assignments->getForLearner((int) $request['id'], $this->actor())
            ),
        ]));
    }

    public function createAttempt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $lease = \OJML\Playgrounds\Ops\Lease::acquire('learner:' . $this->actor());
            $created = $this->assignments->createAttempt((int) $request['id'], $this->actor(), $this->body($request));
            return $this->result([
                'attempt' => $this->presenter->attempt($created['attempt']),
                'created' => $created['created'],
            ], $created['created'] ? 201 : 200);
        });
    }

    public function saveProgress(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'attempt' => $this->presenter->attempt($this->assignments->saveProgress(
                (string) $request['uuid'],
                $this->actor(),
                $this->body($request)
            )),
        ]));
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $files = $request->get_file_params();
            $upload = isset($files['artifact']) && is_array($files['artifact']) ? $files['artifact'] : [];
            $submitted = $this->submissions->submit(
                (string) $request['uuid'],
                $this->actor(),
                $upload,
                $this->correlationId()
            );
            return $this->result([
                'receipt' => $submitted['receipt'],
                'attempt' => $this->presenter->attempt($submitted['attempt']),
            ], $submitted['httpStatus']);
        });
    }

    public function ownResult(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'attempt' => $this->presenter->attempt(
                $this->assignments->ownResult((string) $request['uuid'], $this->actor())
            ),
        ]));
    }

    public function teachingContexts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $page = $this->assignments->teachingContexts(
                $this->actor(),
                is_string($request->get_param('search')) ? (string) $request->get_param('search') : '',
                is_string($request->get_param('cursor')) ? (string) $request->get_param('cursor') : '',
                (int) ($request->get_param('limit') ?: 30)
            );
            return $this->result($page);
        });
    }

    public function teacherAssignments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $status = $request->get_param('status');
            $perPage = $request->get_param('per_page');
            if (!is_numeric($perPage)) {
                $perPage = $request->get_param('perPage');
            }
            $page = $this->assignments->teacherAssignments(
                $this->actor(),
                (int) ($request->get_param('page') ?: 1),
                is_numeric($perPage) ? (int) $perPage : 20,
                is_string($status) && $status !== '' ? sanitize_key($status) : null
            );
            return $this->result([
                'items' => array_map(
                    fn (array $item): array => $this->presenter->assignment($item, true),
                    $page['items']
                ),
                'page' => $page['page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
            ]);
        });
    }

    public function reportAssignments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $status = $request->get_param('status');
            $perPage = $request->get_param('per_page');
            if (!is_numeric($perPage)) {
                $perPage = $request->get_param('perPage');
            }
            $page = $this->assignments->reportAssignments(
                $this->actor(),
                (int) ($request->get_param('page') ?: 1),
                is_numeric($perPage) ? (int) $perPage : 20,
                is_string($status) && $status !== '' ? sanitize_key($status) : null
            );
            return $this->result([
                'items' => array_map(
                    fn (array $item): array => $this->presenter->assignmentSummary($item),
                    $page['items']
                ),
                'page' => $page['page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
            ]);
        });
    }

    public function reportAssignmentOptions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (): array {
            $page = $this->assignments->reportAssignmentOptions($this->actor());
            return $this->result([
                'items' => array_map(
                    fn (array $item): array => $this->presenter->assignmentSummary($item),
                    $page['items']
                ),
                'total' => $page['total'],
            ]);
        });
    }

    public function createAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {

        return $this->run(fn (): array => $this->result([
            'assignment' => $this->presenter->assignment(
                $this->assignments->createAssignment($this->body($request), $this->actor()),
                true
            ),
        ], 201));
    }

    public function teacherAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'assignment' => $this->presenter->assignment(
                $this->assignments->teacherAssignment((int) $request['id'], $this->actor(), 'view'),
                true
            ),
        ]));
    }

    public function updateAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'assignment' => $this->presenter->assignment(
                $this->assignments->updateAssignment((int) $request['id'], $this->body($request), $this->actor()),
                true
            ),
        ]));
    }

    public function deleteAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $body = $this->body($request);
            $revision = isset($body['expectedRevision']) && is_numeric($body['expectedRevision'])
                ? (int) $body['expectedRevision']
                : 0;
            $this->assignments->deleteAssignment((int) $request['id'], $revision, $this->actor());
            return $this->result(['deleted' => true]);
        });
    }

    public function publishAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $body = $this->body($request);
            $revision = isset($body['expectedRevision']) && is_numeric($body['expectedRevision'])
                ? (int) $body['expectedRevision']
                : 0;
            return $this->result([
                'assignment' => $this->presenter->assignment(
                    $this->assignments->publishAssignment((int) $request['id'], $revision, $this->actor()),
                    true
                ),
            ]);
        });
    }

    public function teacherResults(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $status = $request->get_param('status');
            $userId = $request->get_param('user_id');
            if (!is_numeric($userId)) {
                $userId = $request->get_param('userId');
            }
            $perPage = $request->get_param('per_page');
            if (!is_numeric($perPage)) {
                $perPage = $request->get_param('perPage');
            }
            $page = $this->assignments->teacherResults(
                (int) $request['id'],
                $this->actor(),
                (int) ($request->get_param('page') ?: 1),
                is_numeric($perPage) ? (int) $perPage : 20,
                is_string($status) && $status !== '' ? sanitize_key($status) : null,
                is_numeric($userId) ? (int) $userId : null,
                is_string($request->get_param('search')) ? $request->get_param('search') : ''
            );
            $this->primeResultUsers($page['items']);
            return $this->result([
                'assignment' => $page['assignment'],
                'items' => array_map(
                    fn (array $row): array => $this->presenter->resultRow($row),
                    $page['items']
                ),
                'page' => $page['page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
            ]);
        });
    }

    public function teacherResult(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'attempt' => $this->presenter->attempt(
                $this->assignments->teacherResult((string) $request['uuid'], $this->actor(), 'report'),
                true
            ),
        ]));
    }

    public function review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'review' => $this->presenter->review($this->assignments->review(
                (string) $request['uuid'],
                $this->actor(),
                $this->body($request),
                $this->correlationId()
            ), true),
        ], 201));
    }

    public function release(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function () use ($request): array {
            $body = $this->body($request);
            $version = isset($body['expectedVersion']) && is_numeric($body['expectedVersion'])
                ? (int) $body['expectedVersion']
                : 0;
            return $this->result([
                'review' => $this->presenter->review($this->assignments->releaseReview(
                    (string) $request['uuid'],
                    $this->actor(),
                    $version,
                    $this->correlationId()
                ), true),
            ], 201);
        });
    }

    public function health(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn (): array => $this->result([
            'health' => $this->submissions->health() + ['runtime' => $this->contexts->diagnostics()],
        ]));
    }

    /** @param list<array<string,mixed>> $rows */
    private function primeResultUsers(array $rows): void
    {
        if (!function_exists('cache_users')) {
            return;
        }
        $userIds = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
        if ($userIds !== []) {
            cache_users(array_values($userIds));
        }
    }

    private function route(string $path, string $methods, string $callback, string $permission): void
    {
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $methods,
            'callback' => [$this, $callback],
            'permission_callback' => [Permissions::class, $permission],
        ]);
    }

    /** @param callable():array{payload:array<string,mixed>,status:int} $callback */
    private function run(callable $callback): WP_REST_Response|WP_Error
    {
        $correlationId = $this->correlationId();
        try {
            $result = $callback();
            $response = new WP_REST_Response(['data' => $result['payload']], $result['status']);
            $response->header('Cache-Control', 'no-store, private');
            $response->header('Vary', 'Cookie');
            $response->header('X-Correlation-ID', $correlationId);
            return $response;
        } catch (Problem $problem) {
            return $problem->toWpError($correlationId);
        } catch (Throwable $throwable) {
            try {
                do_action('oj_ml_playgrounds_rest_exception', $throwable, $correlationId);
            } catch (Throwable) {
                // Diagnostic listeners must not escape the REST error boundary.
            }
            return (new Problem('OJ_ML_INTERNAL_ERROR', '요청을 처리하는 중 서버 오류가 발생했습니다.', 500))
                ->toWpError($correlationId);
        }
    }

    /** @param array<string,mixed> $payload @return array{payload:array<string,mixed>,status:int} */
    private function result(array $payload, int $status = 200): array
    {
        return ['payload' => $payload, 'status' => $status];
    }

    /** @return array<string,mixed> */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        return is_array($body) ? $body : [];
    }

    private function actor(): int
    {
        $actor = get_current_user_id();
        if ($actor < 1) {
            throw new Problem('OJ_ML_AUTHENTICATION_REQUIRED', '로그인이 필요합니다.', 401);
        }
        return $actor;
    }

    private function correlationId(): string
    {
        static $value = null;
        if (is_string($value)) {
            return $value;
        }
        $candidate = isset($_SERVER['HTTP_X_REQUEST_ID']) && is_string($_SERVER['HTTP_X_REQUEST_ID'])
            ? trim($_SERVER['HTTP_X_REQUEST_ID'])
            : '';
        $value = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $candidate) === 1
            ? $candidate
            : wp_generate_uuid4();
        return $value;
    }
}



