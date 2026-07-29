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
 * Maps POST /mcp → angeo_mcp/mcp/index.
 *
 * Path matching is done on the trimmed path info, not on a prefix, so
 * LiteSpeed / proxy setups that pass "/mcp/" or "//mcp" still match
 * (angeo/module-ucp v1.2.0 LiteSpeed lesson).
 *
 * @since 1.0.0
 */
class Router implements RouterInterface
{
    private const PATH = 'mcp';

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = strtolower(trim($request->getPathInfo() ?? '', '/'));
        if ($path !== self::PATH) {
            return null;
        }

        $request->setModuleName('angeo_mcp')
            ->setControllerName('mcp')
            ->setActionName('index');

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
