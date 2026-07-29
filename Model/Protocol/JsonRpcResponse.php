<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * Immutable JSON-RPC 2.0 response builder.
 *
 * @since 1.0.0
 */
final class JsonRpcResponse
{
    private function __construct(
        private readonly string|int|null $id,
        private readonly ?array $result,
        private readonly ?array $error
    ) {
    }

    public static function result(string|int|null $id, array $result): self
    {
        return new self($id, $result, null);
    }

    public static function error(string|int|null $id, int $code, string $message): self
    {
        return new self($id, null, ['code' => $code, 'message' => $message]);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public function getErrorCode(): ?int
    {
        return $this->error['code'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->id];
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        } else {
            $payload['result'] = $this->result ?? [];
        }
        return $payload;
    }

    public function toJson(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        // json_encode of a plain array with substitution flags cannot fail here,
        // but never emit an empty body on a transport channel.
        return $json === false
            ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Encoding error"}}'
            : $json;
    }
}
