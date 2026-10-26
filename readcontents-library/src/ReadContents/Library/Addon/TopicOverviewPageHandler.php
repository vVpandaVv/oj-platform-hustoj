<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Content\ContentStatus;
use ReadContents\Library\Editor\PublicSite\TopicOverviewRenderer;
use ReadContents\Library\Editor\Storage\ContentRepository;

final class TopicOverviewPageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $slug = sanitize_title((string) ($request->parameters()['topic_slug'] ?? ''));
        $repository = new ContentRepository();
        $topic = '' !== $slug ? $repository->getNodeBySlug($slug) : null;

        if (! $topic || ContentNodeType::TOPIC !== $topic->type || ContentStatus::PUBLISHED !== $topic->status) {
            return PageResponse::reader(
                '주제를 찾을 수 없습니다',
                '<div class="rcis-library">'
                    . PageResponse::emptyState(
                        '주제를 찾을 수 없습니다',
                        '주소가 바뀌었거나 아직 공개되지 않은 주제입니다.',
                        PageResponse::libraryUrl(),
                        '읽기자료 목록으로 돌아가기'
                    )
                    . '</div>',
                404
            );
        }

        $tree = $repository->buildTopicTree($topic->id);

        return PageResponse::reader(
            $topic->title,
            (new TopicOverviewRenderer())->render($tree)
        );
    }
}
