<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * Immutable JSON-RPC 2.0 request as used by MCP Streamable HTTP.
 *
 * Parsing rules (MCP spec 2025-06-18 and later):
 *  - body must be a single JSON object (JSON-RPC batching was REMOVED from
 *    the MCP spec in 2025-06-18 — arrays are rejected with -32600);
 *  - "jsonrpc" must be exactly "2.0";
 *  - a request has an "id" (string|int) and a "method";
 *  - a notification has a "method" but NO "id" — it gets no response body.
 *
 * @since 1.0.0
 */
class JsonRpcRequest
{
    private function __construct(
        private readonly string|int|null $id,
        private readonly string $method,
        private readonly array $params,
        private readonly bool $isNotification
    ) {
    }

    /**
     * @throws ProtocolException with a JSON-RPC error code on malformed input
     */
    public static function fromJson(string $body): self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ProtocolException('Parse error: body is not valid JSON', ErrorCodes::PARSE_ERROR);
        }

        // A JSON array (list) means a batch — removed from MCP in 2025-06-18.
        if (array_is_list($decoded)) {
            throw new ProtocolException(
                'Invalid request: JSON-RPC batching is not supported by MCP (spec 2025-06-18)',
                ErrorCodes::INVALID_REQUEST
            );
        }

        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw new ProtocolException('Invalid request: jsonrpc must be "2.0"', ErrorCodes::INVALID_REQUEST);
        }

        $method = $decoded['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw new ProtocolException('Invalid request: missing method', ErrorCodes::INVALID_REQUEST);
        }

        $hasId = array_key_exists('id', $decoded);
        $id = $decoded['id'] ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            // JSON-RPC allows null ids, MCP forbids them for requests.
            throw new ProtocolException('Invalid request: id must be a string or integer', ErrorCodes::INVALID_REQUEST);
        }

        $params = $decoded['params'] ?? [];
        if (!is_array($params)) {
            throw new ProtocolException('Invalid request: params must be an object', ErrorCodes::INVALID_REQUEST);
        }

        return new self($hasId ? $id : null, $method, $params, !$hasId);
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function isNotification(): bool
    {
        return $this->isNotification;
    }

    /**
     * The request's `params._meta` object, or [] when absent.
     *
     * Since MCP 2026-07-28 every request carries its own protocol version,
     * client identity and client capabilities here instead of negotiating
     * them once in an `initialize` handshake.
     *
     * @return array<string, mixed>
     * @since 2.3.0
     */
    public function getMeta(): array
    {
        $meta = $this->params['_meta'] ?? null;
        return is_array($meta) ? $meta : [];
    }

    /**
     * `_meta["io.modelcontextprotocol/protocolVersion"]`, or null.
     *
     * @since 2.3.0
     */
    public function getMetaProtocolVersion(): ?string
    {
        $version = $this->getMeta()['io.modelcontextprotocol/protocolVersion'] ?? null;
        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Value the `Mcp-Name` header must mirror: `params.name` for tools/call and
     * prompts/get, `params.uri` for resources/read. Null for other methods.
     *
     * @since 2.3.0
     */
    public function getRoutingName(): ?string
    {
        if (!in_array($this->method, ['tools/call', 'prompts/get', 'resources/read'], true)) {
            return null;
        }
        $value = $this->method === 'resources/read'
            ? ($this->params['uri'] ?? null)
            : ($this->params['name'] ?? null);

        return is_string($value) ? $value : null;
    }
}
