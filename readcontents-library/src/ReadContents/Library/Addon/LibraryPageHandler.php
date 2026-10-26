<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Content\ContentStatus;
use ReadContents\Library\Editor\PublicSite\ContentTreeRenderer;
use ReadContents\Library\Editor\Storage\ContentRepository;

final class LibraryPageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        unset($request);

        $repository = new ContentRepository();
        $topics = $repository->listNodes(
            [
                'type' => ContentNodeType::TOPIC->value,
                'status' => ContentStatus::PUBLISHED->value,
            ]
        );
        $trees = array_map(
            static fn ($topic): array => $repository->buildTopicTree($topic->id),
            $topics
        );

        return PageResponse::reader(
            '읽기자료',
            (new ContentTreeRenderer())->render($trees)
        );
    }
}
