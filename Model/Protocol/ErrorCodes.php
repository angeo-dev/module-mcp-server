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
final class ErrorCodes
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

    private function __construct()
    {
    }
}
