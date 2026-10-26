<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SiteDateBoundaryConverter;
use DateTimeImmutable;
use InvalidArgumentException;

final class AiUsageFilter
{
    public readonly string $from;
    public readonly string $to;
    public readonly string $startUtc;
    public readonly string $endUtc;
    public readonly string $groupBy;
    public readonly int $page;
    public readonly array $matches;

    public function __construct(array $input, Clock $siteClock)
    {
        $now = $siteClock->now();
        $this->from = self::text($input, 'from') ?: $now->format('Y-m-01');
        $this->to = self::text($input, 'to') ?: $now->format('Y-m-d');
        $dates = new SiteDateBoundaryConverter($siteClock);
        $this->startUtc = $dates->startUtc($this->from);
        $this->endUtc = $dates->endUtc($this->to);
        $days = (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to));
        if ($this->from > $this->to || $days->days > 366) {
            throw new InvalidArgumentException('조회 기간은 시작일 이후 최대 1년으로 지정해 주세요.');
        }
        $groupBy = self::text($input, 'group_by') ?: 'task_type';
        if (! in_array($groupBy, ['task_type', 'profile_key', 'provider_key', 'model_key', 'purpose', 'addon_id'], true)) {
            throw new InvalidArgumentException('집계 기준이 올바르지 않습니다.');
        }
        $this->groupBy = $groupBy;
        $this->page = max(1, min(10000, (int) self::text($input, 'page')));
        $matches = [];
        foreach (['purpose', 'profile_key', 'provider_key', 'model_key', 'task_type', 'addon_id'] as $key) {
            $value = self::text($input, $key);
            $limit = in_array($key, ['model_key', 'task_type'], true) ? 191 : 100;
            if (strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('검색 조건이 올바르지 않습니다.');
            }
            if ($value !== '') { $matches[$key] = $value; }
        }
        if (isset($matches['purpose']) && ! in_array($matches['purpose'], ['core', 'addon', 'probe'], true)) {
            throw new InvalidArgumentException('사용 구분이 올바르지 않습니다.');
        }
        $this->matches = $matches;
    }

    public function query(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'group_by' => $this->groupBy] + $this->matches;
    }

    private static function text(array $input, string $key): string
    {
        $value = is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('검색 조건이 올바르지 않습니다.');
        }
        return trim($value);
    }
}
