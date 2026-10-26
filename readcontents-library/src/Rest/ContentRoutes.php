<?php
/**
 * REST routes for content hierarchy and lesson documents.
 *
 * @package ContentsEditor\Rest
 */

declare(strict_types=1);

namespace ContentsEditor\Rest;

use ContentsEditor\Content\ContentNode;
use ContentsEditor\Content\ContentNodeType;
use ContentsEditor\Content\ContentStatus;
use ContentsEditor\ImportExport\ContentPackageService;
use ContentsEditor\Storage\ContentRepository;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ContentRoutes
{
    public function __construct(private readonly ContentRepository $repository)
    {
    }

    public function register(): void
    {
        register_rest_route(
            'contents-editor/v1',
            '/topics',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'listTopics'],
                    'permission_callback' => [$this, 'canReadContent'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'createTopic'],
                    'permission_callback' => [$this, 'canManageContent'],
                ],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/topics/(?P<id>[\d]+)/tree',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getTopicTree'],
                'permission_callback' => [$this, 'canReadContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/topics/(?P<id>[\d]+)/package',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exportTopicPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes/(?P<id>[\d]+)/package',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exportNodePackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/packages/preview',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'previewPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/packages/preview-zip',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'previewZipPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/packages/import',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'importPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/packages/import-zip',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'importZipPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'listNodes'],
                    'permission_callback' => [$this, 'canReadContent'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'createNode'],
                    'permission_callback' => [$this, 'canManageContent'],
                ],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes/bulk-status',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'bulkUpdateNodeStatus'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes/(?P<id>[\d]+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'getNode'],
                    'permission_callback' => [$this, 'canReadContent'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'updateNode'],
                    'permission_callback' => [$this, 'canManageContent'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'archiveNode'],
                    'permission_callback' => [$this, 'canManageContent'],
                ],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes/(?P<id>[\d]+)/delete',
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteNode'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/nodes/(?P<id>[\d]+)/restore',
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'restoreNode'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            'contents-editor/v1',
            '/lessons/(?P<id>[\d]+)/document',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'getLessonDocument'],
                    'permission_callback' => [$this, 'canReadContent'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'saveLessonDocument'],
                    'permission_callback' => [$this, 'canManageContent'],
                ],
            ]
        );
    }

    public function canReadContent(): bool
    {
        return true;
    }

    public function canManageContent(): bool
    {
        return current_user_can(CONTENTS_EDITOR_CAPABILITY);
    }

    public function listTopics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $filters = [
                    'type' => ContentNodeType::TOPIC->value,
                    'includeArchived' => $this->canManageContent() && $request->get_param('includeArchived'),
                ];

                if (! $this->canManageContent()) {
                    $filters['status'] = ContentStatus::PUBLISHED->value;
                }

                $topics = $this->repository->listNodes($filters);

                if ($request->get_param('includeTree')) {
                    return array_map(
                        function (ContentNode $topic): array {
                            $tree = $this->repository->buildTopicTree($topic->id);

                            return $this->canManageContent() ? $tree : $this->publishedTree($tree);
                        },
                        $topics
                    );
                }

                return $this->repository->nodesToArray($topics);
            }
        );
    }

    public function createTopic(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $payload = $this->payload($request);
                $payload['type'] = ContentNodeType::TOPIC->value;
                $payload['parentId'] = null;

                return $this->repository->createNode($payload)->toArray();
            },
            201
        );
    }

    public function getTopicTree(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $topic = $this->repository->getNode((string) $request['id']);
                $this->assertReadableNode($topic);

                $tree = $this->repository->buildTopicTree((string) $request['id']);

                return $this->canManageContent() ? $tree : $this->publishedTree($tree);
            }
        );
    }

    public function exportTopicPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => (new ContentPackageService($this->repository))->exportTopic((string) $request['id'])
        );
    }

    public function exportNodePackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => (new ContentPackageService($this->repository))->exportNode((string) $request['id'])
        );
    }

    public function previewPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                ['package' => $package] = $this->packagePayloadAndOptions($request);

                return (new ContentPackageService($this->repository))->previewTopicPackage($package);
            }
        );
    }

    public function previewZipPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $package = $this->uploadedPackageFile($request);

                return (new ContentPackageService($this->repository))->previewZipPackage((string) $package['tmp_name']);
            }
        );
    }

    public function importPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                ['package' => $package, 'options' => $options] = $this->packagePayloadAndOptions($request);

                return (new ContentPackageService($this->repository))->importTopicPackage($package, $options);
            },
            201
        );
    }

    public function importZipPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $package = $this->uploadedPackageFile($request);

                return (new ContentPackageService($this->repository))->importZipPackage(
                    (string) $package['tmp_name'],
                    $this->zipImportOptions($request)
                );
            },
            201
        );
    }

    public function listNodes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $filters = [];

                if ($request->get_param('type')) {
                    $filters['type'] = (string) $request->get_param('type');
                }

                if ($request->get_param('parentId') || '0' === $request->get_param('parentId')) {
                    $filters['parentId'] = $request->get_param('parentId');
                }

                if ($this->canManageContent() && $request->get_param('status')) {
                    $filters['status'] = (string) $request->get_param('status');
                } elseif (! $this->canManageContent()) {
                    $filters['status'] = ContentStatus::PUBLISHED->value;
                }

                $filters['includeArchived'] = $this->canManageContent() && $request->get_param('includeArchived');

                return $this->repository->nodesToArray($this->repository->listNodes($filters));
            }
        );
    }

    public function createNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->createNode($this->payload($request))->toArray(),
            201
        );
    }

    public function getNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $node = $this->repository->getNode((string) $request['id']);
                $this->assertReadableNode($node);

                return $node->toArray();
            }
        );
    }

    public function updateNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->updateNode((string) $request['id'], $this->payload($request))->toArray()
        );
    }

    public function bulkUpdateNodeStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $payload = $this->payload($request);
                $node_ids = $payload['nodeIds'] ?? null;
                $status = $payload['status'] ?? null;

                if (! is_array($node_ids)) {
                    throw new InvalidArgumentException(__('변경할 목차 항목을 선택해 주세요.', 'contents-editor'));
                }

                $node_ids = array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                static fn (mixed $node_id): string => trim((string) $node_id),
                                $node_ids
                            ),
                            static fn (string $node_id): bool => '' !== $node_id
                        )
                    )
                );

                if (! $node_ids) {
                    throw new InvalidArgumentException(__('변경할 목차 항목을 선택해 주세요.', 'contents-editor'));
                }

                if (! is_string($status) || '' === trim($status)) {
                    throw new InvalidArgumentException(__('변경할 상태를 선택해 주세요.', 'contents-editor'));
                }

                $updated = [];

                foreach ($node_ids as $node_id) {
                    $updated[] = $this->repository->updateNode(
                        $node_id,
                        [
                            'status' => $status,
                        ]
                    )->toArray();
                }

                return [
                    'count' => count($updated),
                    'status' => $status,
                    'updated' => $updated,
                ];
            }
        );
    }

    public function archiveNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->archiveNode((string) $request['id'])->toArray()
        );
    }

    public function deleteNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->deleteNode((string) $request['id'])
        );
    }

    public function restoreNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->restoreNode((string) $request['id'])->toArray()
        );
    }

    public function getLessonDocument(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $node = $this->repository->getNode((string) $request['id']);
                $this->assertReadableNode($node);

                return $this->repository->getLessonDocument((string) $request['id'])->toArray();
            }
        );
    }

    public function saveLessonDocument(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => $this->repository->saveLessonDocument((string) $request['id'], $this->payload($request))->toArray()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();

        if (is_array($json)) {
            return $json;
        }

        return $request->get_params();
    }

    /**
     * @return array{package: array<string, mixed>, options: array<string, mixed>}
     */
    private function packagePayloadAndOptions(WP_REST_Request $request): array
    {
        $payload = $this->payload($request);

        if (isset($payload['package']) && is_array($payload['package'])) {
            return [
                'package' => $payload['package'],
                'options' => isset($payload['options']) && is_array($payload['options'])
                    ? $payload['options']
                    : [],
            ];
        }

        return [
            'package' => $payload,
            'options' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadedPackageFile(WP_REST_Request $request): array
    {
        $files = $request->get_file_params();
        $package = $files['package'] ?? null;

        if (! is_array($package) || empty($package['tmp_name'])) {
            throw new InvalidArgumentException(__('ZIP package file is required.', 'contents-editor'));
        }

        if (UPLOAD_ERR_OK !== (int) ($package['error'] ?? UPLOAD_ERR_OK)) {
            throw new InvalidArgumentException(__('ZIP package upload failed.', 'contents-editor'));
        }

        return $package;
    }

    /**
     * @return array<string, mixed>
     */
    private function zipImportOptions(WP_REST_Request $request): array
    {
        $policy = $request->get_param('lessonConflictPolicy');
        $policy_by_source_id = $this->zipLessonConflictPolicyMap($request);
        $options = [];

        if (is_string($policy) && '' !== $policy) {
            $options['lessonConflictPolicy'] = $policy;
        }

        if ($policy_by_source_id) {
            $options['lessonConflictPolicyBySourceId'] = $policy_by_source_id;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function zipLessonConflictPolicyMap(WP_REST_Request $request): array
    {
        $policy_by_source_id = $request->get_param('lessonConflictPolicyBySourceId');

        if (is_array($policy_by_source_id)) {
            return $policy_by_source_id;
        }

        if (! is_string($policy_by_source_id) || '' === trim($policy_by_source_id)) {
            return [];
        }

        $decoded = json_decode($policy_by_source_id, true);

        if (! is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException(__('중복 레슨 개별 처리 방식이 올바른 JSON 형식이 아닙니다.', 'contents-editor'));
        }

        return $decoded;
    }

    /**
     * @param callable(): array<string, mixed>|array<int, mixed> $callback
     */
    private function respond(callable $callback, int $status = 200): WP_REST_Response|WP_Error
    {
        try {
            return new WP_REST_Response($callback(), $status);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error(
                'contents_editor_invalid_request',
                $exception->getMessage(),
                [
                    'status' => 400,
                ]
            );
        } catch (Throwable $exception) {
            return new WP_Error(
                'contents_editor_request_failed',
                $exception->getMessage(),
                [
                    'status' => 500,
                ]
            );
        }
    }

    private function assertReadableNode(?ContentNode $node): void
    {
        if (! $node) {
            throw new InvalidArgumentException(__('Content node was not found.', 'contents-editor'));
        }

        if (! $this->canManageContent() && ContentStatus::PUBLISHED !== $node->status) {
            throw new InvalidArgumentException(__('Content node is not public.', 'contents-editor'));
        }
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function publishedTree(array $tree): array
    {
        $tree['chapters'] = array_values(
            array_filter(
                array_map(
                    function (array $chapter_group): ?array {
                        $chapter = $chapter_group['chapter'] ?? null;

                        if (! is_array($chapter) || ! $this->isPublishedNodeArray($chapter)) {
                            return null;
                        }

                        $chapter_group['sections'] = array_values(
                            array_filter(
                                array_map(
                                    function (array $section_group): ?array {
                                        $section = $section_group['section'] ?? null;

                                        if (! is_array($section) || ! $this->isPublishedNodeArray($section)) {
                                            return null;
                                        }

                                        $lessons = is_array($section_group['lessons'] ?? null)
                                            ? $section_group['lessons']
                                            : [];

                                        $section_group['lessons'] = array_values(
                                            array_filter(
                                                $lessons,
                                                fn (mixed $lesson): bool => is_array($lesson) && $this->isPublishedNodeArray($lesson)
                                            )
                                        );

                                        return $section_group;
                                    },
                                    is_array($chapter_group['sections'] ?? null) ? $chapter_group['sections'] : []
                                )
                            )
                        );

                        return $chapter_group;
                    },
                    is_array($tree['chapters'] ?? null) ? $tree['chapters'] : []
                )
            )
        );

        return $tree;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isPublishedNodeArray(array $node): bool
    {
        return ContentStatus::PUBLISHED->value === (string) ($node['status'] ?? '');
    }
}
