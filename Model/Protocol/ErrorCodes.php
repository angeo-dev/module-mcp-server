<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * JSON-RPC 2.0 / MCP error codes.
 *
 * @since 1.0.0
 */
class ErrorCodes
{
    public const PARSE_ERROR      = -32700;
    public const INVALID_REQUEST  = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS   = -32602;
    public const INTERNAL_ERROR   = -32603;

    /** Server-defined: request rejected by rate limiter (HTTP 429 as well). */
    public const RATE_LIMITED     = -32000;
    /** Server-defined: missing/invalid Bearer token (HTTP 401 as well). */
    public const UNAUTHORIZED     = -32001;

    /**
     * MCP 2026-07-28: codes from the range the specification reserves for
     * itself (-32020 … -32099). All three are answered with HTTP 400.
     *
     * The draft numbered these -32001/-32003/-32004; the final text moved them
     * so they no longer collide with implementation-defined codes such as
     * UNAUTHORIZED above.
     *
     * @since 2.3.0
     */
    public const HEADER_MISMATCH                    = -32020;
    /** @since 2.3.0 */
    public const MISSING_REQUIRED_CLIENT_CAPABILITY = -32021;
    /** @since 2.3.0 */
    public const UNSUPPORTED_PROTOCOL_VERSION       = -32022;

    private function __construct()
    {
    }
}
