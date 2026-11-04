<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use DomainException;

final class OperationError extends DomainException
{
    public readonly string $requestId;
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $safeMessage,
        public readonly ?int $retryAfterSeconds = null,
        ?string $requestId = null,
        public readonly ?string $fieldPath = null,
    ) {
        parent::__construct($safeMessage);
        $this->requestId = RequestContext::validId($requestId) ? $requestId : RequestContext::id();
    }
    public function data(): array
    {
        $data = ['status' => $this->httpStatus, 'requestId' => $this->requestId];
        if ($this->retryAfterSeconds !== null) { $data['retryAfterSeconds'] = max(1, min(300, $this->retryAfterSeconds)); }
        if ($this->fieldPath !== null) { $data['fieldPath'] = $this->fieldPath; }
        return $data;
    }
}
