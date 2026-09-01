<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Api;

/**
 * Optional companion to ToolInterface: tools implementing this interface get
 * their behavioral annotations exposed in the MCP tools/list response.
 *
 * Kept as a SEPARATE interface (not a new method on ToolInterface) on
 * purpose: ToolInterface is @api since 1.0.0 and third-party tools implement
 * it; adding a method there would be a BC break. The server feature-detects
 * with instanceof, so existing tools keep working unchanged and simply expose
 * no annotations.
 *
 * Annotation semantics follow the MCP specification (ToolAnnotations):
 *  - readOnlyHint: the tool does not modify its environment;
 *  - destructiveHint: the tool may perform irreversible updates
 *    (meaningful only when readOnlyHint is false);
 *  - idempotentHint: repeated calls with the same arguments have no
 *    additional effect (meaningful only when readOnlyHint is false);
 *  - openWorldHint: the tool interacts with an open world of external
 *    entities (e.g. web search); Magento commerce tools operate on a closed
 *    domain and should return false.
 *
 * Per the spec these are HINTS for clients, not security guarantees — but
 * they must honestly describe tool behavior: directory reviewers check that
 * a tool annotated read-only truly performs no writes, and that
 * order-creating tools are flagged destructive.
 *
 * @api
 * @since 1.1.0
 */
interface ToolAnnotationsInterface
{
    /**
     * @return array{
     *     title?: string,
     *     readOnlyHint?: bool,
     *     destructiveHint?: bool,
     *     idempotentHint?: bool,
     *     openWorldHint?: bool
     * }
     */
    public function getAnnotations(): array;
}
