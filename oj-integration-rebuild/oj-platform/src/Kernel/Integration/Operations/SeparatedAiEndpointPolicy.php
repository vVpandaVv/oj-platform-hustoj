<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointValidation;
use CodingPanda\OjPlatform\Modules\Settings\Contract\HostAddressResolver;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiEndpointAddress;
use DomainException;

final class SeparatedAiEndpointPolicy implements AiEndpointPolicy
{
    /**
     * @param list<string> $additionalJudgeHosts
     */
    public function __construct(
        private readonly HostAddressResolver $addresses,
        private readonly JudgeConnectionSettingsProvider $judgeSettings,
        private readonly array $additionalJudgeHosts = []
    ) {
    }

    public function validate(
        AiProviderDefinition $definition,
        string $baseUrl
    ): AiEndpointValidation {
        $parts = parse_url(trim($baseUrl));
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new DomainException(
                'AI 연결 주소는 사용자 정보·쿼리·조각이 없는 HTTPS 주소여야 합니다.'
            );
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($definition->key() === 'openai') {
            if ($host !== 'api.openai.com' || $path !== '') {
                throw new DomainException('OpenAI API 주소는 https://api.openai.com으로 고정됩니다.');
            }
        } elseif ($definition->key() === 'gemini') {
            if (
                $host !== 'generativelanguage.googleapis.com'
                || $path !== '/v1beta'
            ) {
                throw new DomainException(
                    'Gemini API 주소는 공식 v1beta 주소로 고정됩니다.'
                );
            }
        } elseif ($path !== '') {
            throw new DomainException(
                '자체 AI 서버와 Gateway의 Base URL에는 사용자 정의 하위 경로를 넣을 수 없습니다.'
            );
        }
        $resolved = AiEndpointAddress::canonicalList($this->addresses->resolve($host));
        if ($resolved === []) {
            throw new DomainException('AI 서버 주소를 확인하지 못했습니다.');
        }
        foreach ($resolved as $address) {
            if (AiEndpointAddress::isForbidden($address)) {
                throw new DomainException(
                    'AI 서버 주소가 루프백·링크 로컬·메타데이터 네트워크를 가리킵니다.'
                );
            }
        }
        $judgeAddresses = $this->judgeAddresses();
        if (array_intersect($resolved, $judgeAddresses) !== []) {
            throw new DomainException(
                'AI 서버와 Judge 서버는 같은 주소를 사용할 수 없습니다.'
            );
        }
        sort($resolved);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $normalized = 'https://' . $this->hostForUrl($host) . $port . $path;

        return new AiEndpointValidation($normalized, $resolved);
    }

    /**
     * @return list<string>
     */
    private function judgeAddresses(): array
    {
        $hosts = $this->additionalJudgeHosts;
        try {
            $settings = $this->judgeSettings->current();
            if ($settings !== null) {
                $hosts[] = $settings->host();
            }
        } catch (\Throwable) {
        }
        $addresses = [];
        foreach (array_values(array_unique($hosts)) as $host) {
            if (! is_string($host) || trim($host) === '') {
                continue;
            }
            $addresses = array_merge(
                $addresses,
                $this->addresses->resolve(trim($host))
            );
        }
        return AiEndpointAddress::canonicalList($addresses);
    }

    private function hostForUrl(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $host . ']'
            : $host;
    }
}
