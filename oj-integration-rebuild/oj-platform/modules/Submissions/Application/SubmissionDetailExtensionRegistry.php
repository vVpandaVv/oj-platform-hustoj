<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionDetailExtension;
use LogicException;

final class SubmissionDetailExtensionRegistry
{
    /**
     * @var array<string, SubmissionDetailExtension>
     */
    private array $extensions = [];

    /** @var list<\CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAdminDetailPanel> */
    private array $adminPanels = [];

    public function registerAdmin(\CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAdminDetailPanel $panel): void
    {
        $this->adminPanels[] = $panel;
    }

    public function renderAdmin(int $actor, string $login, int $submissionId): string
    {
        $content = '';
        foreach ($this->adminPanels as $panel) {
            $content .= $panel->renderAdmin($actor, $login, $submissionId);
        }
        return $content;
    }

    public function register(SubmissionDetailExtension $extension): void
    {
        $key = $extension->key();
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1
            || isset($this->extensions[$key])
        ) {
            throw new LogicException('제출 상세 확장 키가 올바르지 않거나 중복되었습니다.');
        }
        $this->extensions[$key] = $extension;
    }

    public function isEmpty(): bool
    {
        return $this->extensions === [];
    }

    public function render(
        int $actorUserId,
        string $actorUserLogin,
        AiFeedbackSubmission $submission
    ): string {
        $content = '';
        foreach ($this->extensions as $extension) {
            $content .= $extension->render(
                $actorUserId,
                $actorUserLogin,
                $submission
            );
        }

        return $content;
    }
}
