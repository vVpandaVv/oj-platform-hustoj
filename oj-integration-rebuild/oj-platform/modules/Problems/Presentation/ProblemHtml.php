<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Ui\Editor\RichTextContent;

final class ProblemHtml
{
    /**
     * @var array<string, ProblemTopic>|null
     */
    private ?array $topicMap = null;

    public function __construct(
        private readonly ProblemTopicDictionary $topics,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function date(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->dates->format($value, $format);
    }

    public function richText(string $value): string
    {
        return '<div class="ojp-prose ojp-math-content" data-ojp-latex>'
            . $this->editorContent($value) . '</div>';
    }

    public function editorContent(string $value): string
    {
        return (new RichTextContent())->render($value);
    }

    public function code(string $value, string $label): string
    {
        return '<div class="ojp-code-block"><div class="ojp-code-block__label">'
            . $this->escape($label)
            . '</div><pre><code>' . $this->escape($value) . '</code></pre></div>';
    }

    public function difficulty(?ProblemMetadata $metadata): string
    {
        $score = $metadata?->difficulty()?->value();
        if ($score === null) {
            return '<span class="ojp-status-badge ojp-status-badge--neutral">미분석</span>';
        }
        $label = match ($score) {
            1 => '1 · 입문',
            2 => '2 · 기초',
            3 => '3 · 보통',
            4 => '4 · 어려움',
            5 => '5 · 매우 어려움',
        };

        return '<span class="ojp-status-badge ojp-status-badge--difficulty-'
            . $score . '">' . $label . '</span>';
    }

    public function specialJudgeBadge(bool $specialJudge): string
    {
        if (! $specialJudge) {
            return '';
        }

        return '<span class="ojp-status-badge ojp-status-badge--info" '
            . 'title="전용 채점 프로그램으로 정답을 검사합니다.">특수 채점</span>';
    }

    public function topicBadges(?ProblemMetadata $metadata): string
    {
        if ($metadata === null || $metadata->topicKeys() === []) {
            return '<span class="ojp-muted">주제 미지정</span>';
        }
        $badges = '';
        $titles = [];
        foreach ($metadata->topicKeys() as $topicKey) {
            $topic = $this->topicMap()[$topicKey] ?? null;
            $label = $topic?->label() ?? $topicKey;
            $category = $topic?->categoryKey();
            $categoryLabel = $category === null
                ? ''
                : (($this->topicMap()[$category] ?? null)?->label() ?? $category);
            $text = $categoryLabel === '' ? $label : $categoryLabel . ' · ' . $label;
            $titles[] = $text;
            $badges .= '<span class="ojp-tag">' . $this->escape($text) . '</span>';
        }

        return '<span class="ojp-tag-list" title="'
            . $this->escape(implode(', ', $titles)) . '">' . $badges . '</span>';
    }

    /**
     * @return array<string, ProblemTopic>
     */
    private function topicMap(): array
    {
        if ($this->topicMap !== null) {
            return $this->topicMap;
        }
        $map = [];
        foreach ($this->topics->all() as $topic) {
            $map[$topic->key()] = $topic;
        }
        $this->topicMap = $map;

        return $map;
    }
}
