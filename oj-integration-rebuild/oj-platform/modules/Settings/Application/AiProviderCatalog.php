<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderDefinition;
use DomainException;

final class AiProviderCatalog
{
    public const TASK_TYPES = [
        'problem_metadata_generate',
        'problem_authoring_generate',
        'problem_testcase_generate',
        'submission_feedback',
        'feedback_prompt_validation',
    ];

    /**
     * @var array<string, AiProviderDefinition>
     */
    private readonly array $definitions;

    public function __construct(
        private readonly AddonAiTaskContributions $addonTasks = new AddonAiTaskContributions()
    ) {
        $definitions = [
            new AiProviderDefinition(
                'openai',
                'OpenAI API',
                'openai_compatible',
                'https://api.openai.com',
                'model-id',
                ['bearer'],
                false,
                "Base URL: https://api.openai.com\n인증: Bearer\n모델: 계정에서 사용할 수 있는 모델 ID",
                "GET /v1/models\nPOST /v1/chat/completions\nAuthorization: Bearer {API_KEY}"
            ),
            new AiProviderDefinition(
                'gemini',
                'Gemini API',
                'gemini',
                'https://generativelanguage.googleapis.com/v1beta',
                'models/gemini-model-id',
                ['x_api_key'],
                false,
                "Base URL: https://generativelanguage.googleapis.com/v1beta\n인증: X-API-Key\n모델: models/gemini-*",
                "GET /v1beta/models\nPOST /v1beta/models/{model}:generateContent\nx-goog-api-key: {API_KEY}"
            ),
            new AiProviderDefinition(
                'ollama',
                'Ollama',
                'ollama',
                'https://ai.example.com',
                'gemma4:latest',
                ['bearer', 'x_api_key'],
                true,
                "Base URL: https://ai.example.com\n인증: Reverse Proxy의 Bearer 또는 X-API-Key\n모델: gemma4:latest",
                "GET /api/tags\nPOST /api/chat\nstream: false · think: false"
            ),
            new AiProviderDefinition(
                'mlx_lm',
                'MLX-LM',
                'openai_compatible',
                'https://ai.example.com',
                'model-id',
                ['bearer', 'x_api_key'],
                true,
                "Base URL: Apple Silicon AI 서버의 HTTPS Reverse Proxy\n인증: Bearer 또는 X-API-Key\n모델: /v1/models에 등록한 모델 ID",
                "GET /v1/models\nPOST /v1/chat/completions\nstream: false"
            ),
            new AiProviderDefinition(
                'vllm',
                'vLLM',
                'openai_compatible',
                'https://ai.example.com',
                'served-model-name',
                ['bearer'],
                true,
                "Base URL: GPU AI 서버의 HTTPS 주소\n인증: --api-key로 설정한 Bearer\n모델: --served-model-name 값",
                "GET /v1/models\nPOST /v1/chat/completions\nAuthorization: Bearer {API_KEY}"
            ),
            new AiProviderDefinition(
                'sglang',
                'SGLang',
                'openai_compatible',
                'https://ai.example.com',
                'served-model-name',
                ['bearer'],
                true,
                "Base URL: GPU AI 서버의 HTTPS 주소\n인증: 서버 API Key Bearer\n모델: 등록한 served model",
                "GET /v1/models\nPOST /v1/chat/completions\n관리 API는 호출하지 않음"
            ),
            new AiProviderDefinition(
                'openai_compatible_gateway',
                'OpenAI 호환 Gateway',
                'openai_compatible',
                'https://gateway.example.com',
                'gateway-model-id',
                ['bearer', 'x_api_key'],
                false,
                "Base URL: 별도 HTTPS Gateway\n인증: Bearer 또는 X-API-Key\n경로: /v1/models, /v1/chat/completions 고정",
                "GET /v1/models\nPOST /v1/chat/completions\n쿠키·임의 헤더·사용자 정의 경로 금지"
            ),
        ];
        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->key()] = $definition;
        }
        $this->definitions = $indexed;
    }

    /**
     * @return list<AiProviderDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $key): AiProviderDefinition
    {
        $definition = $this->definitions[$key] ?? null;
        if (! $definition instanceof AiProviderDefinition) {
            throw new DomainException('지원하지 않는 AI Provider입니다.');
        }

        return $definition;
    }

    public function supportsTask(string $taskType): bool
    {
        return in_array($taskType, self::TASK_TYPES, true) || $this->addonTasks->has($taskType);
    }

    public function taskLabel(string $taskType): string
    {
        if ($this->addonTasks->has($taskType)) {
            return $this->addonTasks->label($taskType);
        }

        return match ($taskType) {
            'problem_metadata_generate' => '문제 정보 생성',
            'problem_authoring_generate' => '문제 초안 생성',
            'problem_testcase_generate' => '테스트케이스 초안',
            'submission_feedback' => '제출 AI 피드백',
            'feedback_prompt_validation' => '피드백 프롬프트 검증 (합성 코드)',
            default => throw new DomainException('등록되지 않은 AI 작업입니다.'),
        };
    }

    /**
     * Core task keys plus every key declared by a currently-accepted addon.
     * Core call sites that must stay core-only keep using TASK_TYPES directly.
     *
     * @return list<string>
     */
    public function allTaskKeys(): array
    {
        return array_merge(self::TASK_TYPES, $this->addonTasks->keys());
    }

    public function isAddonTask(string $taskType): bool
    {
        return $this->addonTasks->has($taskType);
    }

    public function taskContractFingerprint(string $taskType): string
    {
        if ($this->addonTasks->has($taskType)) {
            return $this->addonTasks->contractFingerprint($taskType);
        }
        if (! in_array($taskType, self::TASK_TYPES, true)) {
            throw new DomainException('등록되지 않은 AI 작업입니다.');
        }

        return hash('sha256', 'core-task-contract-v1|' . $taskType);
    }

    /**
     * Single source of truth for whether a task type may only be assigned a
     * submission-source-approved provider profile — core's own
     * submission_feedback plus whatever addon tasks declared
     * sourceKind=platform_submission.
     */
    public function requiresSubmissionSource(string $taskType): bool
    {
        return $taskType === 'submission_feedback'
            || $this->addonTasks->requiresSubmissionSource($taskType);
    }
}
