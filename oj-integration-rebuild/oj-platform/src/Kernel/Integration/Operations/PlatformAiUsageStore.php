<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiUsageStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiUsageFilter;
use RuntimeException;

final class PlatformAiUsageStore implements AiUsageStore
{
    private const FIELDS = ['request_id', 'occurred_at', 'profile_key', 'provider_key', 'model_key', 'task_type', 'addon_id', 'purpose', 'outcome', 'error_code', 'http_status', 'duration_ms', 'input_tokens', 'output_tokens', 'total_tokens', 'cached_input_tokens', 'reasoning_tokens', 'measurement', 'total_source'];
    private const SUMMARY = "COUNT(*) AS request_count, SUM(outcome = 'succeeded') AS success_count, SUM(outcome = 'failed') AS failure_count, SUM(measurement = 'complete') AS complete_count, SUM(measurement = 'partial') AS partial_count, SUM(measurement = 'unavailable') AS unavailable_count, COUNT(total_tokens) AS total_measured_count, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(total_tokens) AS total_tokens, SUM(cached_input_tokens) AS cached_input_tokens, SUM(reasoning_tokens) AS reasoning_tokens, AVG(duration_ms) AS average_ms";

    public function __construct(private readonly ApplicationDatabase $db) {}

    public function append(array $record): void
    {
        $data = array_intersect_key($record, array_flip(self::FIELDS));
        if (count($data) !== count(self::FIELDS) || $this->db->insert('ai_usage_logs', $data) < 1) {
            throw new RuntimeException('AI 사용량 기록에 실패했습니다.');
        }
    }

    public function report(AiUsageFilter $filter): array
    {
        $where = ' WHERE occurred_at >= %s AND occurred_at <= %s';
        $values = [$filter->startUtc, $filter->endUtc];
        foreach ($filter->matches as $key => $value) {
            $where .= " AND `{$key}` = %s";
            $values[] = $value;
        }
        $summary = $this->db->row('SELECT ' . self::SUMMARY . ' FROM ai_usage_logs' . $where, $values);
        if ($summary === null || ! is_numeric($summary['request_count'] ?? null)) {
            throw new RuntimeException('AI 사용량 저장소를 확인할 수 없습니다. 설치 상태를 확인해 주세요.');
        }
        $pages = max(1, (int) ceil((int) $summary['request_count'] / 50));
        $page = min($filter->page, $pages);
        $group = $filter->groupBy;
        $groups = $this->db->rows("SELECT `{$group}` AS group_key, " . self::SUMMARY . ' FROM ai_usage_logs' . $where
            . " GROUP BY `{$group}` ORDER BY request_count DESC, group_key ASC LIMIT 100", $values);
        $items = $this->db->rows('SELECT ' . implode(', ', self::FIELDS) . ' FROM ai_usage_logs' . $where
            . ' ORDER BY occurred_at DESC, id DESC LIMIT 50 OFFSET %d', [...$values, ($page - 1) * 50]);
        return ['summary' => $summary, 'groups' => $groups, 'items' => $items, 'page' => $page, 'pages' => $pages];
    }
}
