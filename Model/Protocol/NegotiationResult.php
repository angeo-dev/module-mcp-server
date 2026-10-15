<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * Outcome of ProtocolNegotiator::negotiate(): the era and version to serve the
 * request in, or an error response with the HTTP status to send it with.
 *
 * @since 2.3.0
 */
class NegotiationResult
{
    private function __construct(
        private readonly string $era,
        private readonly ?string $version,
        private readonly ?JsonRpcResponse $error,
        private readonly int $httpStatus
    ) {
    }

    public static function legacy(): self
    {
        return new self(ProtocolNegotiator::ERA_LEGACY, null, null, 200);
    }

    public static function modern(string $version): self
    {
        return new self(ProtocolNegotiator::ERA_MODERN, $version, null, 200);
    }

    public static function failed(JsonRpcResponse $error, int $httpStatus): self
    {
        return new self(ProtocolNegotiator::ERA_MODERN, null, $error, $httpStatus);
    }

    public function isModern(): bool
    {
        return $this->era === ProtocolNegotiator::ERA_MODERN && $this->error === null;
    }

    public function getEra(): string
    {
        return $this->era;
    }

    /** Modern-era version, e.g. "2026-07-28"; null for legacy or failures. */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getError(): ?JsonRpcResponse
    {
        return $this->error;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
