<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpResponse;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\HostAddressResolver;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiEndpointAddress;

final class WordPressAiHttpTransport implements AiHttpTransport
{
    public function __construct(private readonly HostAddressResolver $addresses)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        array $expectedResolvedIps
    ): AiHttpResponse {
        if (! function_exists('wp_remote_request')) {
            throw new AiProviderException(
                'transport_unavailable',
                'WordPress HTTP 전송 기능을 사용할 수 없습니다.',
                true
            );
        }
        $host = $this->assertEndpoint($method, $url, $expectedResolvedIps);
        if (
            ! function_exists('curl_setopt_array')
            || ! defined('CURLOPT_RESOLVE')
            || ! function_exists('add_action')
            || ! function_exists('remove_action')
        ) {
            throw new AiProviderException(
                'transport_unavailable',
                '안전한 AI 연결에는 PHP cURL 확장과 WordPress HTTP Hook이 필요합니다.',
                false
            );
        }
        $ips = AiEndpointAddress::canonicalList($expectedResolvedIps);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        $pinned = false;
        $pinConnection = static function (mixed $handle) use ($host, $port, $ips, &$pinned): void {
            $addresses = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $ips
            );
            if (! curl_setopt_array($handle, [
                CURLOPT_RESOLVE => AiEndpointAddress::canonical($host) === null
                    ? [$host . ':' . $port . ':' . implode(',', $addresses)]
                    : [],
                // A proxy may perform its own DNS lookup, outside this pin.
                CURLOPT_PROXY => '',
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ])) {
                throw new AiProviderException('transport_unavailable', 'AI 연결 주소를 안전하게 고정하지 못했습니다.', false);
            }
            $pinned = true;
        };
        $allowConfiguredHost = static function (
            bool $external,
            string $candidateHost
        ) use ($host): bool {
            return strtolower(trim($candidateHost, '[]')) === $host
                ? true
                : $external;
        };
        $filterRegistered = function_exists('add_filter')
            && function_exists('remove_filter');
        // Bind the connection, not just the earlier DNS check. Keep the URL
        // hostname intact so TLS verification and SNI still use that name.
        $configureRequest = static function (
            string $requestUrl,
            array $requestHeaders,
            mixed $requestBody,
            string $requestMethod,
            array &$options
        ) use ($url, $method, $headers, $connectTimeoutSeconds, $timeoutSeconds, $pinConnection): void {
            if (
                $requestUrl !== $url
                || strtoupper($requestMethod) !== strtoupper($method)
                || ($options['useragent'] ?? '') !== 'OJ-Platform-AI/1.0'
                || ($requestHeaders['X-OJ-Request-Id'] ?? null)
                    !== ($headers['X-OJ-Request-Id'] ?? null)
            ) {
                return;
            }
            if (
                ! is_object($options['hooks'] ?? null)
                || ! method_exists($options['hooks'], 'register')
                || ! empty($options['proxy'])
            ) {
                throw new AiProviderException(
                    'transport_unavailable',
                    'AI 연결은 주소 고정을 지원하는 직접 HTTPS 연결만 사용할 수 있습니다.',
                    false
                );
            }
            $options['connect_timeout'] = max(1, min($connectTimeoutSeconds, $timeoutSeconds));
            $options['transport'] = '\\WpOrg\\Requests\\Transport\\Curl';
            $options['hooks']->register('curl.before_send', $pinConnection);
        };
        $actionRegistered = function_exists('add_action')
            && function_exists('remove_action');
        if ($filterRegistered) {
            add_filter(
                'http_request_host_is_external',
                $allowConfiguredHost,
                10,
                3
            );
        }
        if ($actionRegistered) {
            add_action('requests-requests.before_request', $configureRequest, 10, 5);
        }
        $startedAt = microtime(true);
        try {
            $response = wp_remote_request($url, [
                'method' => strtoupper($method),
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeoutSeconds,
                'redirection' => 0,
                'sslverify' => true,
                'reject_unsafe_urls' => true,
                'limit_response_size' => 2097152,
                'user-agent' => 'OJ-Platform-AI/1.0',
            ]);
        } finally {
            if ($actionRegistered) {
                remove_action('requests-requests.before_request', $configureRequest, 10);
            }
            if ($filterRegistered) {
                remove_filter(
                    'http_request_host_is_external',
                    $allowConfiguredHost,
                    10
                );
            }
        }
        if (! $pinned) {
            throw new AiProviderException(
                'transport_unavailable',
                'AI 연결 주소 고정을 확인하지 못했습니다.',
                false
            );
        }
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $detail = is_object($response) && method_exists($response, 'get_error_message')
                ? trim((string) $response->get_error_message())
                : '';
            throw new AiProviderException(
                'provider_unavailable',
                $detail !== ''
                    ? 'AI Provider에 연결하지 못했습니다: ' . $detail
                    : 'AI Provider에 연결하지 못했습니다.',
                true
            );
        }
        if (! is_array($response)) {
            throw new AiProviderException(
                'invalid_response',
                'AI Provider 응답을 읽지 못했습니다.',
                true
            );
        }
        $status = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : (int) ($response['response']['code'] ?? 0);
        $responseBody = function_exists('wp_remote_retrieve_body')
            ? (string) wp_remote_retrieve_body($response)
            : (string) ($response['body'] ?? '');
        if (strlen($responseBody) > 2097152) {
            throw new AiProviderException(
                'response_too_large',
                'AI Provider 응답이 2MB를 초과했습니다.',
                false
            );
        }
        $rawHeaders = is_array($response['headers'] ?? null)
            ? $response['headers']
            : [];
        $normalizedHeaders = [];
        foreach ($rawHeaders as $key => $value) {
            if (is_scalar($value)) {
                $normalizedHeaders[strtolower((string) $key)] = (string) $value;
            }
        }

        return new AiHttpResponse(
            $status,
            $normalizedHeaders,
            $responseBody,
            max(0, (int) round((microtime(true) - $startedAt) * 1000))
        );
    }

    /**
     * @param list<string> $expectedResolvedIps
     */
    private function assertEndpoint(
        string $method,
        string $url,
        array $expectedResolvedIps
    ): string {
        $parts = parse_url($url);
        if (
            ! in_array(strtoupper($method), ['GET', 'POST'], true)
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new AiProviderException(
                'invalid_endpoint',
                'AI Provider 요청 주소가 승인된 HTTPS 형식이 아닙니다.',
                false
            );
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $currentIps = AiEndpointAddress::canonicalList($this->addresses->resolve($host));
        $expectedIps = AiEndpointAddress::canonicalList($expectedResolvedIps);
        if ($currentIps === [] || $expectedIps === [] || $currentIps !== $expectedIps) {
            throw new AiProviderException(
                'endpoint_changed',
                'AI 서버 주소가 후보 검사 시점과 달라졌습니다.',
                false
            );
        }
        $literalAddress = AiEndpointAddress::canonical($host);
        if ($literalAddress !== null && $currentIps !== [$literalAddress]) {
            throw new AiProviderException('invalid_endpoint', 'AI 연결 주소와 확인한 IP가 일치하지 않습니다.', false);
        }
        foreach ($currentIps as $ip) {
            if (AiEndpointAddress::isForbidden($ip)) {
                throw new AiProviderException('invalid_endpoint', 'AI 연결에서 허용하지 않는 네트워크 주소입니다.', false);
            }
        }

        return $host;
    }
}
