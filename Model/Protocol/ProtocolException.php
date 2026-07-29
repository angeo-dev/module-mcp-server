<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * Protocol-level failure carrying its JSON-RPC error code.
 *
 * @since 1.0.0
 */
class ProtocolException extends \RuntimeException
{
    public function __construct(string $message, int $jsonRpcCode)
    {
        parent::__construct($message, $jsonRpcCode);
    }
}
