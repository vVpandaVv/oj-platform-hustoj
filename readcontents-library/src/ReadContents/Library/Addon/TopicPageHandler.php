<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use ReadContents\Library\Editor\Content\ContentAccessPolicy;
use ReadContents\Library\Editor\Content\ContentNode;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Content\ContentStatus;
use ReadContents\Library\Editor\PublicSite\LessonOutlineRenderer;
use ReadContents\Library\Editor\PublicSite\PlateHtmlRenderer;
use ReadContents\Library\Editor\Storage\ContentRepository;

final class TopicPageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $slug = sanitize_title((string) ($request->parameters()['topic_id'] ?? ''));
        $repository = new ContentRepository();
        $lesson = '' !== $slug ? $repository->getNodeBySlug($slug) : null;

        if (! $this->isViewable($lesson, $request->actorUserId(), $repository)) {
            return PageResponse::reader(
                '레슨을 찾을 수 없습니다',
                '<div class="rcis-library">'
                    . PageResponse::emptyState(
                        '레슨을 찾을 수 없습니다',
                        '주소가 바뀌었거나 아직 공개되지 않은 레슨입니다.',
                        PageResponse::libraryUrl(),
                        '읽기자료 목록으로 돌아가기'
                    )
                    . '</div>',
                404
            );
        }

        $document = $repository->getLessonDocument($lesson->id);
        $is_draft = ContentStatus::PUBLISHED !== $lesson->status;
        $html = '<section class="rcis-reader" data-rcis-reader data-outline-open="true">'
            . '<div class="rcis-reader__toolbar"><button type="button" data-rcis-outline-toggle aria-controls="rcis-reader-outline" aria-expanded="true">목차 숨기기</button></div>'
            . '<div class="rcis-reader__layout" data-rcis-reader-layout>'
            . '<aside id="rcis-reader-outline" class="rcis-reader__outline" data-rcis-reader-outline aria-label="레슨 목차">'
            . $this->renderOutline($lesson, $repository)
            . '</aside>'
            . '<div class="rcis-reader__resize" role="separator" aria-label="목차 너비 조절" aria-orientation="vertical" aria-controls="rcis-reader-outline" aria-valuemin="220" aria-valuemax="560" aria-valuenow="280" tabindex="0" data-rcis-reader-resize title="끌거나 좌우 방향키로 목차 너비 조절 · 두 번 클릭하면 기본 너비"></div>'
            . '<article class="rcis-reading rcis-story rcis-reader__content" data-contents-editor-runtime="public" data-lesson-id="' . esc_attr($lesson->id) . '">';
        $html .= $this->renderBreadcrumb($lesson, $repository);

        if ($is_draft) {
            $html .= '<p class="ojp-notice ojp-notice--warning" role="status">초안 미리보기입니다. 학생에게는 보이지 않습니다.</p>';
        }

        $html .= (new PlateHtmlRenderer())->render($document->plateValue);
        $html .= $this->renderSectionSiblings($lesson, $repository);
        $html .= '</article></div></section>';
        $html .= '<script type="module" src="'
            . esc_url(add_query_arg('ver', rawurlencode(READCONTENTS_LIBRARY_VERSION), READCONTENTS_LIBRARY_URL . 'assets/editor/public.js'))
            . '"></script>';
        $html .= '<script type="module" src="'
            . esc_url(add_query_arg('ver', rawurlencode(READCONTENTS_LIBRARY_VERSION), READCONTENTS_LIBRARY_URL . 'assets/reader-diagrams.js'))
            . '"></script>';
        $html .= '<script type="module" src="'
            . esc_url(add_query_arg('ver', rawurlencode(READCONTENTS_LIBRARY_VERSION), READCONTENTS_LIBRARY_URL . 'assets/reader-layout.js'))
            . '"></script>';

        return PageResponse::lesson($lesson->title, $html);
    }

    private function renderOutline(ContentNode $lesson, ContentRepository $repository): string
    {
        $section = $lesson->parentId ? $repository->getNode($lesson->parentId) : null;
        $chapter = $section && ContentNodeType::SECTION === $section->type && $section->parentId
            ? $repository->getNode($section->parentId) : null;
        $topic = $chapter && ContentNodeType::CHAPTER === $chapter->type && $chapter->parentId
            ? $repository->getNode($chapter->parentId) : null;
        $tree = $topic && ContentNodeType::TOPIC === $topic->type
            ? $repository->buildTopicTree($topic->id) : [];

        return (new LessonOutlineRenderer())->render($tree, $lesson->id);
    }

    private function renderBreadcrumb(ContentNode $lesson, ContentRepository $repository): string
    {
        $section = $lesson->parentId ? $repository->getNode($lesson->parentId) : null;
        $chapter = $section && $section->parentId ? $repository->getNode($section->parentId) : null;
        $topic = $chapter && $chapter->parentId ? $repository->getNode($chapter->parentId) : null;

        $crumbs = ['<a href="' . esc_url(PageResponse::libraryUrl()) . '">읽기자료</a>'];

        if ($topic instanceof ContentNode) {
            $crumbs[] = '<a href="' . esc_url(home_url(AddonRoutes::topicOverviewPath($topic->slug))) . '">' . esc_html($topic->title) . '</a>';
        }

        if ($chapter instanceof ContentNode) {
            $crumbs[] = esc_html($chapter->title);
        }

        return '<nav class="ojp-breadcrumb rcis-breadcrumb" aria-label="읽기자료 경로">' . implode(
            '<span class="rcis-breadcrumb__sep" aria-hidden="true">›</span>',
            $crumbs
        ) . '</nav>';
    }

    private function renderSectionSiblings(ContentNode $lesson, ContentRepository $repository): string
    {
        if (! $lesson->parentId) {
            return '';
        }

        $siblings = array_values(array_filter(
            $repository->listNodes([
                'type' => ContentNodeType::LESSON->value,
                'parentId' => $lesson->parentId,
            ]),
            static fn (ContentNode $node): bool => ContentStatus::PUBLISHED === $node->status
        ));

        if (count($siblings) < 2) {
            return '';
        }

        // Siblings share the lesson's parent grouping inside a 장. Its own
        // title heads the list; the grouping has no UI term (D-010 11장).
        $section = $repository->getNode($lesson->parentId);
        $heading = $section instanceof ContentNode && '' !== trim($section->title) ? $section->title : '함께 읽을 레슨';

        $html = '<nav class="rcis-section-siblings" aria-label="함께 읽을 레슨">';
        $html .= '<h2 class="rcis-section-siblings__title">' . esc_html($heading) . '</h2>';
        $html .= '<ol class="rcis-section-siblings__list">';

        foreach ($siblings as $sibling) {
            $is_current = $sibling->id === $lesson->id;
            $html .= '<li' . ($is_current ? ' class="rcis-section-siblings__current" aria-current="page"' : '') . '>';

            if ($is_current) {
                $html .= esc_html($sibling->title);
            } else {
                $html .= '<a href="' . esc_url(home_url(AddonRoutes::topicPath($sibling->slug))) . '">' . esc_html($sibling->title) . '</a>';
            }

            $html .= '</li>';
        }

        return $html . '</ol></nav>';
    }

    private function isViewable(
        ?ContentNode $lesson,
        int $actor_user_id,
        ContentRepository $repository
    ): bool
    {
        if (! $lesson || ContentNodeType::LESSON !== $lesson->type) {
            return false;
        }

        $can_edit = user_can($actor_user_id, AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_EDIT))
            || user_can($actor_user_id, AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_PUBLISH));

        $access_policy = new ContentAccessPolicy(
            fn (string $id): ?ContentNode => $repository->getNode($id)
        );

        return $can_edit || $access_policy->isPubliclyReadable($lesson);
    }
}
