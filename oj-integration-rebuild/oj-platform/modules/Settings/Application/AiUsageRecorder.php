<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiUsageStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTokenUsage;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class AiUsageRecorder
{
    private array $context = ['task_type' => 'unclassified', 'purpose' => 'core'];
    private array $attempts = [];

    public function __construct(
        private readonly AiUsageStore $store,
        private readonly Clock $clock,
        private readonly ErrorReporter $errors
    ) {}

    public function withTask(string $taskType, bool $probe, callable $work): array
    {
        $previous = $this->context;
        $this->context = ['task_type' => $taskType, 'purpose' => $probe ? 'probe' : (str_starts_with($taskType, 'addon.') ? 'addon' : 'core')];
        try { return $work(); }
        finally { $this->context = $previous; }
    }

    public function measure(AiProviderProfile $profile, callable $work): array
    {
        $start = microtime(true);
        $parts = explode('.', $this->context['task_type']);
        $this->attempts[] = $this->context + [
            'request_id' => null,
            'occurred_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'profile_key' => $profile->key(), 'provider_key' => $profile->providerKey(),
            'model_key' => $profile->model(), 'addon_id' => $parts[0] === 'addon' ? ($parts[1] ?? '') : '',
            'http_status' => null, 'outcome' => 'succeeded', 'error_code' => '',
        ] + AiTokenUsage::parse($profile->providerKey(), []);
        try {
            return $work();
        } catch (Throwable $error) {
            $index = array_key_last($this->attempts);
            $this->attempts[$index]['outcome'] = 'failed';
            $code = $error instanceof AiProviderException ? $error->safeCode() : 'generation_failed';
            $this->attempts[$index]['error_code'] = preg_match('/^[a-z0-9_]{1,100}$/D', $code) === 1 ? $code : 'generation_failed';
            throw $error;
        } finally {
            $record = array_pop($this->attempts);
            if ($record['request_id'] !== null) {
                $record['duration_ms'] = max(0, (int) round((microtime(true) - $start) * 1000));
                try { $this->store->append($record); }
                catch (Throwable) {
                    // Telemetry failure must neither discard an AI answer nor trigger a paid retry.
                    try {
                        $this->errors->report(new RuntimeException('AI 사용량 기록에 실패했습니다. 사용량 저장소를 확인해 주세요.'));
                    } catch (Throwable) {
                        // The diagnostic sink is also optional to the generation result.
                    }
                }
            }
        }
    }

    public function sending(string $requestId): void
    {
        $index = array_key_last($this->attempts);
        if ($index !== null) { $this->attempts[$index]['request_id'] = $requestId; }
    }

    public function received(int $httpStatus, array $response): void
    {
        $index = array_key_last($this->attempts);
        if ($index === null) { return; }
        $this->attempts[$index] = array_replace($this->attempts[$index],
            AiTokenUsage::parse($this->attempts[$index]['provider_key'], $response), ['http_status' => $httpStatus]);
    }
}
