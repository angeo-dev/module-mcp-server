<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Controller;

use Angeo\McpServer\Controller\Router;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    /**
     * @dataProvider paths
     */
    public function testPublicPaths(string $path, ?string $expected): void
    {
        $request = $this->getMockBuilder(HttpRequest::class)->disableOriginalConstructor()
            ->onlyMethods(['getPathInfo'])->getMock();
        $request->method('getPathInfo')->willReturn($path);

        $factory = $this->createStub(ActionFactory::class);
        $factory->method('create')->willReturn($this->createStub(ActionInterface::class));

        $matched = (new Router($factory))->match($request);

        if ($expected === null) {
            self::assertNull($matched);
            return;
        }
        self::assertNotNull($matched);
        self::assertSame($expected, $request->getControllerName() . '/' . $request->getActionName());
    }

    /** @return array<string, array{string, ?string}> */
    public static function paths(): array
    {
        return [
            'endpoint'          => ['/mcp', 'mcp/index'],
            'trailing slash'    => ['/mcp/', 'mcp/index'],
            'double slash'      => ['//mcp//link', 'link/index'],
            'link page'         => ['/mcp/link', 'link/index'],
            'exchange'          => ['/mcp/link/exchange', 'link/exchange'],
            'token'             => ['/mcp/link/token', 'link/token'],
            'revoke'            => ['/mcp/link/revoke', 'link/revoke'],
            'approve not alias' => ['/mcp/link/approve', null],
            'unknown'           => ['/mcp/other', null],
            'prefix only'       => ['/mcpx', null],
        ];
    }
}
