<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Api;

use Magento\Store\Api\Data\StoreInterface;

/**
 * A single MCP tool exposed by the server.
 *
 * Third-party modules add tools by implementing this interface and
 * registering the class in the ToolRegistry pool via di.xml:
 *
 * <type name="Angeo\McpServer\Model\Tool\ToolRegistry">
 *     <arguments>
 *         <argument name="tools" xsi:type="array">
 *             <item name="my_tool" xsi:type="object">Vendor\Module\Tool\MyTool</item>
 *         </argument>
 *     </arguments>
 * </type>
 *
 * Contract notes:
 *  - getName() must be unique, snake_case, stable across releases (agents
 *    cache tool lists);
 *  - getInputSchema() returns a JSON Schema (draft 2020-12 subset) describing
 *    the arguments object;
 *  - execute() returns a JSON-serializable array — the server wraps it into
 *    the MCP tools/call result (structuredContent + text fallback);
 *  - throw \InvalidArgumentException for bad arguments (mapped to an
 *    isError tool result, NOT a protocol error, per MCP spec);
 *  - NEVER return PII of other sessions/customers from a tool.
 *
 * @api
 * @since 1.0.0
 */
interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /** @return array<string, mixed> JSON Schema for the "arguments" object */
    public function getInputSchema(): array;

    /**
     * Whether the tool is available for this store (config gates, mode gates).
     */
    public function isAvailable(StoreInterface $store): bool;

    /**
     * @param array<string, mixed> $arguments validated against getInputSchema() shape by the caller's client;
     *                                        implementations MUST still validate defensively
     * @return array<string, mixed> JSON-serializable result payload
     * @throws \InvalidArgumentException on invalid arguments (becomes an isError tool result)
     */
    public function execute(array $arguments, StoreInterface $store): array;
}
