<?php
/**
 * REST routes for content hierarchy and lesson documents.
 *
 * @package ReadContentsLibrary\Rest
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Rest;

use ReadContents\Library\Editor\Content\ContentAccessPolicy;
use ReadContents\Library\Editor\Content\ContentRevisionConflict;
use ReadContents\Library\Editor\Content\ContentNode;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use ReadContents\Library\Addon\AddonRoutes;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Content\ContentStatus;
use ReadContents\Library\Editor\ImportExport\ContentPackageService;
use ReadContents\Library\Editor\ImportExport\ServerPackageImporter;
use ReadContents\Library\Editor\Migration\ContentReimportService;
use ReadContents\Library\Editor\Storage\ContentRepository;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ContentRoutes
{
    public const REST_NAMESPACE = 'readcontents-library/v1';

    private readonly ContentAccessPolicy $accessPolicy;

    public function __construct(private readonly ContentRepository $repository)
    {
        $this->accessPolicy = new ContentAccessPolicy(fn (string $id): ?ContentNode => $this->repository->getNode($id));
    }

    public function register(): void
    {
        foreach ([
            '/packages/server' => [WP_REST_Server::READABLE, 'serverPackages'],
            '/packages/server/preview' => [WP_REST_Server::CREATABLE, 'previewServerPackage'],
            '/packages/server/start' => [WP_REST_Server::CREATABLE, 'startServerPackage'],
            '/packages/server/(?P<job>[a-f0-9-]{36})/step' => [WP_REST_Server::CREATABLE, 'stepServerPackage'],
            '/packages/server/(?P<job>[a-f0-9-]{36})/control' => [WP_REST_Server::CREATABLE, 'controlServerPackage'],
        ] as $route => [$methods, $callback]) {
            register_rest_route(self::REST_NAMESPACE, $route, [
                'methods' => $methods,
                'callback' => [$this, $callback],
                'permission_callback' => [$this, 'canImportServerPackages'],
            ]);
        }
        register_rest_route(
            self::REST_NAMESPACE,
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
            self::REST_NAMESPACE,
            '/topics/(?P<id>[\d]+)/tree',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getTopicTree'],
                'permission_callback' => [$this, 'canReadContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/topics/(?P<id>[\d]+)/package',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exportTopicPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/nodes/(?P<id>[\d]+)/package',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exportNodePackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/packages/preview',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'previewPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/packages/preview-zip',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'previewZipPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/packages/import',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'importPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/packages/import-zip',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'importZipPackage'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
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
            self::REST_NAMESPACE,
            '/nodes/bulk-status',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'bulkUpdateNodeStatus'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
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
            self::REST_NAMESPACE,
            '/nodes/(?P<id>[\d]+)/delete',
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteNode'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/nodes/(?P<id>[\d]+)/restore',
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'restoreNode'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
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

        register_rest_route(
            self::REST_NAMESPACE,
            '/nodes/(?P<id>[\d]+)/reimport-from-json',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'reimportLessonFromJson'],
                'permission_callback' => [$this, 'canPublishContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/nodes/reimport-all-from-json',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'reimportAllLessonsFromJson'],
                'permission_callback' => [$this, 'canPublishContent'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'uploadMedia'],
                'permission_callback' => [$this, 'canManageContent'],
            ]
        );
    }

    public function canReadContent(): bool
    {
        return true;
    }

    public function canImportServerPackages(): bool
    {
        return current_user_can('manage_options') && current_user_can('upload_files')
            && $this->canManageContent() && $this->canPublishContent();
    }

    private function serverImporter(): ServerPackageImporter
    {
        return new ServerPackageImporter(new ContentPackageService($this->repository));
    }

    public function serverPackages(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(fn (): array => $this->serverImporter()->info());
    }

    public function previewServerPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(fn (): array => $this->serverImporter()->preview((string) $request->get_param('fileName')));
    }

    public function startServerPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(fn (): array => $this->serverImporter()->start(
            (string) $request->get_param('fileName'),
            (string) $request->get_param('fingerprint'),
            is_array($request->get_param('options')) ? $request->get_param('options') : [],
            (string) $request->get_param('requestId')
        ));
    }

    public function stepServerPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(fn (): array => $this->serverImporter()->step((string) $request['job'], (int) $request->get_param('cursor')));
    }

    public function controlServerPackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(fn (): array => $this->serverImporter()->control((string) $request['job'], (string) $request->get_param('action')));
    }

    public function canManageContent(): bool
    {
        return current_user_can(AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_EDIT));
    }

    public function canPublishContent(): bool
    {
        return current_user_can(AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_PUBLISH));
    }

    public function canViewPrivateContent(): bool
    {
        return $this->canManageContent() || $this->canPublishContent();
    }

    public function listTopics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $filters = [
                    'type' => ContentNodeType::TOPIC->value,
                    'includeArchived' => $this->canViewPrivateContent() && $request->get_param('includeArchived'),
                ];

                if (! $this->canViewPrivateContent()) {
                    $filters['status'] = ContentStatus::PUBLISHED->value;
                }

                $topics = $this->repository->listNodes($filters);

                if ($request->get_param('includeTree')) {
                    return array_map(
                        function (ContentNode $topic): array {
                            $tree = $this->repository->buildTopicTree($topic->id);

                            return $this->canViewPrivateContent() ? $tree : $this->publishedTree($tree);
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
                $this->assertPublishPermission($this->accessPolicy->creationRequiresPublish($payload['status'] ?? null));

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

                return $this->canViewPrivateContent() ? $tree : $this->publishedTree($tree);
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

                if ($this->canViewPrivateContent() && $request->get_param('status')) {
                    $filters['status'] = (string) $request->get_param('status');
                } elseif (! $this->canViewPrivateContent()) {
                    $filters['status'] = ContentStatus::PUBLISHED->value;
                }

                $filters['includeArchived'] = $this->canViewPrivateContent() && $request->get_param('includeArchived');
                $nodes = $this->repository->listNodes($filters);

                if (! $this->canViewPrivateContent()) {
                    $nodes = array_values(array_filter(
                        $nodes,
                        fn (ContentNode $node): bool => $this->accessPolicy->isPubliclyReadable($node)
                    ));
                }

                return $this->repository->nodesToArray($nodes);
            }
        );
    }

    public function createNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $payload = $this->payload($request);
                $this->assertPublishPermission($this->accessPolicy->creationRequiresPublish($payload['status'] ?? null));

                return $this->repository->createNode($payload)->toArray();
            },
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
            function () use ($request): array {
                $node = $this->repository->getNode((string) $request['id']);
                $payload = $this->payload($request);

                if ($node) {
                    $this->assertPublishPermission($this->accessPolicy->statusChangeRequiresPublish($node, $payload['status'] ?? null));
                }

                return $this->repository->updateNode((string) $request['id'], $payload)->toArray();
            }
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
                    throw new InvalidArgumentException(__('변경할 목차 항목을 선택해 주세요.', 'readcontents-library'));
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
                    throw new InvalidArgumentException(__('변경할 목차 항목을 선택해 주세요.', 'readcontents-library'));
                }

                if (! is_string($status) || '' === trim($status)) {
                    throw new InvalidArgumentException(__('변경할 상태를 선택해 주세요.', 'readcontents-library'));
                }

                $nodes = [];

                foreach ($node_ids as $node_id) {
                    $node = $this->repository->getNode($node_id);

                    if (! $node) {
                        throw new InvalidArgumentException(__('Content node was not found.', 'readcontents-library'));
                    }

                    $nodes[] = $node;
                }

                $this->assertPublishPermission(
                    $this->accessPolicy->bulkStatusChangeRequiresPublish($nodes, $status)
                );

                $updated = [];

                foreach ($nodes as $node) {
                    $updated[] = $this->repository->updateNode(
                        $node->id,
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
            function () use ($request): array {
                $node = $this->repository->getNode((string) $request['id']);
                $this->assertPublishPermission($this->accessPolicy->removalRequiresPublish($node));

                $result = $this->repository->archiveNode((string) $request['id'])->toArray();
                $this->auditNodeAction('archived', $result);
                return $result;
            }
        );
    }

    public function deleteNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $node = $this->repository->getNode((string) $request['id']);
                $this->assertPublishPermission($this->accessPolicy->removalRequiresPublish($node));

                $result = $this->repository->deleteNode((string) $request['id']);
                $this->auditNodeAction('deleted', $result['node'], $result['deleted']);
                return $result;
            }
        );
    }

    public function restoreNode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $result = $this->repository->restoreNode((string) $request['id'])->toArray();
                $this->auditNodeAction('restored', $result);
                return $result;
            }
        );
    }

    private function auditNodeAction(string $action, array $node, array $deleted = []): void
    {
        if (function_exists('oj_log_audit_event')) {
            oj_log_audit_event('readcontents_node_' . $action, '읽기자료 항목: ' . $action, [
                'node_id' => $node['id'], 'node_type' => $node['type'], 'deleted' => $deleted,
            ], 'info');
        }
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

    public function reimportLessonFromJson(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => (new ContentReimportService($this->repository))->reimportLesson((string) $request['id'])
        );
    }

    public function reimportAllLessonsFromJson(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            fn (): array => (new ContentReimportService($this->repository))->reimportAllLessons()
        );
    }

    public function uploadMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond(
            function () use ($request): array {
                $files = $request->get_file_params();
                $file = $files['file'] ?? null;

                if (! is_array($file) || empty($file['tmp_name'])) {
                    throw new InvalidArgumentException(__('업로드할 이미지, 영상, 오디오 또는 파일을 선택해 주세요.', 'readcontents-library'));
                }

                if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_OK)) {
                    throw new InvalidArgumentException(__('미디어 파일 업로드가 완료되지 않았습니다.', 'readcontents-library'));
                }

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';

                $field = 'rcis_media_upload';
                $_FILES[$field] = $file;

                try {
                    $attachment_id = media_handle_upload($field, 0);
                } finally {
                    unset($_FILES[$field]);
                }

                if (is_wp_error($attachment_id)) {
                    throw new InvalidArgumentException($attachment_id->get_error_message());
                }

                $url = wp_get_attachment_url((int) $attachment_id);

                if (! is_string($url) || '' === $url) {
                    throw new InvalidArgumentException(__('업로드한 미디어의 공개 주소를 확인하지 못했습니다.', 'readcontents-library'));
                }

                $name = sanitize_file_name((string) ($file['name'] ?? basename($url)));
                $mime_type = (string) get_post_mime_type((int) $attachment_id);

                return [
                    'id' => (int) $attachment_id,
                    'key' => (string) $attachment_id,
                    'appUrl' => $url,
                    'url' => $url,
                    'name' => '' !== $name ? $name : basename($url),
                    'size' => max(0, (int) ($file['size'] ?? 0)),
                    'type' => $mime_type,
                ];
            },
            201
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
            throw new InvalidArgumentException(__('ZIP package file is required.', 'readcontents-library'));
        }

        if (UPLOAD_ERR_OK !== (int) ($package['error'] ?? UPLOAD_ERR_OK)) {
            throw new InvalidArgumentException(__('ZIP package upload failed.', 'readcontents-library'));
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
            throw new InvalidArgumentException(__('중복 레슨 개별 처리 방식이 올바른 JSON 형식이 아닙니다.', 'readcontents-library'));
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
        } catch (ContentRevisionConflict $exception) {
            return new WP_Error(
                'contents_editor_revision_conflict',
                $exception->getMessage(),
                [
                    'status' => 409,
                    'currentRevision' => $exception->currentRevision(),
                ]
            );
        } catch (ContentPermissionException $exception) {
            return new WP_Error(
                'contents_editor_publish_forbidden',
                $exception->getMessage(),
                [
                    'status' => 403,
                ]
            );
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
            throw new InvalidArgumentException(__('Content node was not found.', 'readcontents-library'));
        }

        if (! $this->canViewPrivateContent() && ! $this->accessPolicy->isPubliclyReadable($node)) {
            throw new InvalidArgumentException(__('Content node is not public.', 'readcontents-library'));
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

    private function assertPublishPermission(bool $required): void
    {
        if (! $required || $this->canPublishContent()) {
            return;
        }

        throw new ContentPermissionException(
            __('공개 상태를 변경하거나 공개 콘텐츠를 제거하려면 읽기자료 공개 권한이 필요합니다.', 'readcontents-library')
        );
    }
}
