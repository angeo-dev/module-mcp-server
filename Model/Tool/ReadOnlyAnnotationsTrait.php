<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Tool;

/**
 * Shared annotations for the server's built-in read tools: they perform no
 * writes anywhere (readOnlyHint), and they operate on the store's own closed
 * catalog rather than an open world of external entities (openWorldHint
 * false). Per the MCP spec, destructive/idempotent hints are meaningless when
 * readOnlyHint is true, so they are omitted.
 */
trait ReadOnlyAnnotationsTrait
{
    /**
     * @return array{readOnlyHint: bool, openWorldHint: bool}
     */
    public function getAnnotations(): array
    {
        return [
            'readOnlyHint' => true,
            'openWorldHint' => false,
        ];
    }
}
