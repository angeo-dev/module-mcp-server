<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Maps POST /mcp → angeo_mcp/mcp/index, and the account-linking paths
 * /mcp/link[/exchange|/token|/revoke] (2.4.0).
 *
 * Path matching is done on the trimmed path info, not on a prefix, so
 * LiteSpeed / proxy setups that pass "/mcp/" or "//mcp" still match
 * (angeo/module-ucp v1.2.0 LiteSpeed lesson).
 *
 * @since 1.0.0
 */
class Router implements RouterInterface
{
    /**
     * Stable public paths → controller/action. The /mcp/link/* paths are what
     * an MCP connector (proxy) is configured with, so they never change; the
     * shopper-facing pages the store links to itself use the standard
     * /angeo_mcp/... route.
     */
    private const PATHS = [
        'mcp'              => ['mcp', 'index'],
        'mcp/link'         => ['link', 'index'],     // 2.4.0
        'mcp/link/exchange' => ['link', 'exchange'], // 2.4.0
        'mcp/link/token'   => ['link', 'token'],     // 2.4.0
        'mcp/link/revoke'  => ['link', 'revoke'],    // 2.4.0
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = strtolower(trim($request->getPathInfo() ?? '', '/'));
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        if (!isset(self::PATHS[$path])) {
            return null;
        }
        [$controller, $action] = self::PATHS[$path];

        $request->setModuleName('angeo_mcp')
            ->setControllerName($controller)
            ->setActionName($action);

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
