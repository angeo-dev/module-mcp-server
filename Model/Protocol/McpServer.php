<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

use Angeo\McpServer\Api\ToolAnnotationsInterface;
use Angeo\McpServer\Model\Tool\ToolRegistry;
use Magento\Store\Api\Data\StoreInterface;

/**
 * MCP protocol dispatcher. Transport-agnostic: the frontend controller (and
 * the CLI test command) feed it a JsonRpcRequest and get a JsonRpcResponse
 * (or null for notifications) back. No Magento framework dependencies besides
 * the StoreInterface pass-through, so the whole protocol layer is unit-tested
 * without the framework.
 *
 * Supported methods (MCP spec 2025-06-18):
 *  - initialize                 → capabilities + serverInfo (version negotiation)
 *  - notifications/initialized  → notification, no response
 *  - tools/list                 → tool descriptors from the registry
 *  - tools/call                 → dispatch to a ToolInterface implementation
 *  - ping                       → {}
 *
 * @since 1.0.0
 */
class McpServer
{
    public const SERVER_NAME    = 'Angeo MCP Server for Magento 2';
    public const SERVER_VERSION = '1.0.0';

    /**
     * Newest protocol revision this server implements, plus older revisions
     * it can safely speak (the 1.0 feature set — tools only — is identical
     * across these revisions).
     */
    public const PROTOCOL_VERSION = '2025-06-18';
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly ToolRegistry $toolRegistry
    ) {
    }

    /**
     * @return JsonRpcResponse|null null for notifications (HTTP 202, empty body)
     */
    public function handle(JsonRpcRequest $request, StoreInterface $store): ?JsonRpcResponse
    {
        if ($request->isNotification()) {
            // notifications/initialized, notifications/cancelled, … — accept silently.
            return null;
        }

        $id = $request->getId();

        try {
            return match ($request->getMethod()) {
                'initialize' => $this->initialize($id, $request->getParams()),
                'tools/list' => $this->listTools($id, $store),
                'tools/call' => $this->callTool($id, $request->getParams(), $store),
                'ping'       => JsonRpcResponse::result($id, []),
                default      => JsonRpcResponse::error(
                    $id,
                    ErrorCodes::METHOD_NOT_FOUND,
                    sprintf('Method not found: %s', $request->getMethod())
                ),
            };
        } catch (ProtocolException $e) {
            return JsonRpcResponse::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            // Never leak internals (exception messages may contain paths/SQL).
            return JsonRpcResponse::error($id, ErrorCodes::INTERNAL_ERROR, 'Internal error');
        }
    }

    private function initialize(string|int|null $id, array $params): JsonRpcResponse
    {
        $requested = $params['protocolVersion'] ?? null;
        // Version negotiation per spec: echo the client's version if we
        // support it, otherwise answer with our latest and let the client
        // decide whether to proceed.
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true)
            ? $requested
            : self::PROTOCOL_VERSION;

        return JsonRpcResponse::result($id, [
            'protocolVersion' => $version,
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions'    =>
                'Read-only commerce tools for this Magento 2 store: product search, '
                . 'product details, category tree, and store information. Prices reflect '
                . 'the store\'s public (not-logged-in) customer group unless configured '
                . 'otherwise. All data is live — no caching layer sits between these '
                . 'tools and the catalog.',
        ]);
    }

    private function listTools(string|int|null $id, StoreInterface $store): JsonRpcResponse
    {
        $descriptors = [];
        foreach ($this->toolRegistry->getAvailable($store) as $tool) {
            $descriptor = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];

            // Annotations are opt-in via ToolAnnotationsInterface, so third-party
            // tools implementing only ToolInterface keep working unchanged (no BC
            // break) and simply advertise no hints.
            if ($tool instanceof ToolAnnotationsInterface) {
                $annotations = $tool->getAnnotations();
                if ($annotations !== []) {
                    // MCP exposes the human-readable name both at the top level
                    // and inside annotations; clients may read either.
                    if (isset($annotations['title'])) {
                        $descriptor['title'] = $annotations['title'];
                    }
                    $descriptor['annotations'] = $annotations;
                }
            }

            $descriptors[] = $descriptor;
        }
        return JsonRpcResponse::result($id, ['tools' => $descriptors]);
    }

    private function callTool(string|int|null $id, array $params, StoreInterface $store): JsonRpcResponse
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new ProtocolException('Invalid params: missing tool name', ErrorCodes::INVALID_PARAMS);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new ProtocolException('Invalid params: arguments must be an object', ErrorCodes::INVALID_PARAMS);
        }

        $tool = $this->toolRegistry->get($name, $store);
        if ($tool === null) {
            // Unknown tool is a protocol-level INVALID_PARAMS per MCP spec.
            throw new ProtocolException(sprintf('Unknown tool: %s', $name), ErrorCodes::INVALID_PARAMS);
        }

        try {
            $payload = $tool->execute($arguments, $store);
        } catch (\InvalidArgumentException $e) {
            // Tool-level failure → isError RESULT (agents can read and recover),
            // not a JSON-RPC error, per spec.
            return JsonRpcResponse::result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        } catch (\Throwable) {
            return JsonRpcResponse::result($id, [
                'content' => [['type' => 'text', 'text' => 'Tool execution failed']],
                'isError' => true,
            ]);
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return JsonRpcResponse::result($id, [
            // structuredContent (2025-06-18) + text fallback for older clients.
            'structuredContent' => $payload,
            'content'           => [['type' => 'text', 'text' => $json === false ? '{}' : $json]],
            'isError'           => false,
        ]);
    }
}
