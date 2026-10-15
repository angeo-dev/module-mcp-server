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
class JsonRpcResponse
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

    /**
     * Error with a `data` member, e.g. UnsupportedProtocolVersionError, whose
     * `data.supported` lists the versions the client may retry with.
     *
     * @param array<string, mixed> $data
     * @since 2.3.0
     */
    public static function errorWithData(string|int|null $id, int $code, string $message, array $data): self
    {
        return new self($id, null, ['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * Copy of this result with extra top-level fields merged in. Used to add
     * the fields MCP 2026-07-28 requires on every result (`resultType`,
     * `_meta`, and `ttlMs`/`cacheScope` on list results). Errors are returned
     * unchanged.
     *
     * @param array<string, mixed> $fields
     * @since 2.3.0
     */
    public function withResultFields(array $fields): self
    {
        if ($this->error !== null) {
            return $this;
        }
        $result = $this->result ?? [];
        if (isset($fields['_meta'], $result['_meta']) && is_array($fields['_meta']) && is_array($result['_meta'])) {
            $fields['_meta'] = $result['_meta'] + $fields['_meta'];
        }
        return new self($this->id, $fields + $result, null);
    }

    /**
     * @return array<string, mixed>|null
     * @since 2.3.0
     */
    public function getResult(): ?array
    {
        return $this->result;
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
