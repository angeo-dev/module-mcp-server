<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Api;

/**
 * Optional MCP tool annotations (behavioural hints).
 *
 * Implemented ALONGSIDE ToolInterface. It is deliberately a separate interface
 * so that adding annotations is not a BC break for third-party tools that
 * already implement ToolInterface — those keep working, they simply advertise
 * no hints.
 *
 * The MCP specification defines these hints so a client can decide how much
 * ceremony a call deserves (e.g. Claude confirms with the user before running
 * anything destructive). Anthropic's Connectors Directory review treats missing
 * or wrong annotations as a top rejection cause, and the Software Directory
 * Policy requires that descriptions and hints match actual behaviour — so these
 * values MUST be honest. A tool that writes MUST NOT claim readOnlyHint.
 *
 * Hints are advisory, not a security boundary: clients treat them as untrusted.
 * They never replace server-side authorisation.
 *
 * Recognised keys (all optional):
 *  - title           string  human-readable name shown in client UIs
 *  - readOnlyHint    bool    true  = does not modify any state
 *  - destructiveHint bool    true  = may perform irreversible/destructive updates
 *                                    (only meaningful when readOnlyHint is false)
 *  - idempotentHint  bool    true  = repeating the call with the same arguments
 *                                    has no additional effect
 *  - openWorldHint   bool    true  = interacts with an open set of external
 *                                    entities; false = closed domain
 *
 * @api
 * @since 1.1.0
 */
interface ToolAnnotationsInterface
{
    /**
     * @return array<string, mixed> MCP annotations object; empty array = no hints
     */
    public function getAnnotations(): array;
}
