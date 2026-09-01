<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Protocol;

use Angeo\McpServer\Api\ToolInterface;
use Angeo\McpServer\Model\Protocol\ErrorCodes;
use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\McpServer;
use Angeo\McpServer\Model\Tool\ToolRegistry;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class McpServerTest extends TestCase
{
    private McpServer $server;
    private StoreInterface $store;

    protected function setUp(): void
    {
        $echoTool = new class implements ToolInterface {
            public function getName(): string { return 'echo_tool'; }
            public function getDescription(): string { return 'Echo arguments back'; }
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]];
            }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array
            {
                if (($arguments['msg'] ?? '') === 'boom') {
                    throw new \InvalidArgumentException('bad msg');
                }
                return ['echo' => $arguments['msg'] ?? null];
            }
        };

        $this->server = new McpServer(new ToolRegistry([$echoTool]));
        $this->store = $this->createStub(StoreInterface::class);
    }

    /**
     * The instructions a client reads before deciding whether to use this
     * connector at all. The 1.0 string claimed the server was read-only, which
     * stopped being true the moment module-mcp-checkout was installed — and a
     * connector that says it cannot transact will not be asked to.
     */
    public function testInstructionsDescribeReadOnlyServerHonestly(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        $instructions = $out['result']['instructions'] ?? '';

        self::assertStringContainsString('read-only', strtolower($instructions));
        self::assertStringNotContainsString('place an order', strtolower($instructions));
    }

    public function testInstructionsAnnounceCheckoutWhenOrderToolIsRegistered(): void
    {
        $placeOrder = new class implements ToolInterface {
            public function getName(): string { return 'place_order'; }
            public function getDescription(): string { return 'Place an order.'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array { return []; }
        };

        $server = new McpServer(new ToolRegistry([$placeOrder]));
        $response = $server->handle(
            JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'),
            $this->store
        );
        $instructions = $response->toArray()['result']['instructions'] ?? '';

        self::assertStringContainsString('place an order', strtolower($instructions));
        self::assertStringNotContainsString('these tools are read-only', strtolower($instructions));
    }

    private function handle(string $json): ?array
    {
        $response = $this->server->handle(JsonRpcRequest::fromJson($json), $this->store);
        return $response?->toArray();
    }

    public function testInitializeNegotiatesKnownVersion(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}');
        self::assertSame('2025-03-26', $out['result']['protocolVersion']);
        self::assertSame(McpServer::SERVER_NAME, $out['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $out['result']['capabilities']);
    }

    public function testInitializeFallsBackToLatestOnUnknownVersion(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}');
        self::assertSame(McpServer::PROTOCOL_VERSION, $out['result']['protocolVersion']);
    }

    public function testNotificationGetsNoResponse(): void
    {
        $response = $this->server->handle(
            JsonRpcRequest::fromJson('{"jsonrpc":"2.0","method":"notifications/initialized"}'),
            $this->store
        );
        self::assertNull($response);
    }

    public function testToolsListDescribesTools(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
        self::assertCount(1, $out['result']['tools']);
        self::assertSame('echo_tool', $out['result']['tools'][0]['name']);
        self::assertArrayHasKey('inputSchema', $out['result']['tools'][0]);
    }

    public function testToolsCallReturnsStructuredContent(): void
    {
        $out = $this->handle(
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo_tool","arguments":{"msg":"hi"}}}'
        );
        self::assertFalse($out['result']['isError']);
        self::assertSame(['echo' => 'hi'], $out['result']['structuredContent']);
        self::assertSame('text', $out['result']['content'][0]['type']);
    }

    public function testToolFailureIsToolErrorNotProtocolError(): void
    {
        $out = $this->handle(
            '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"echo_tool","arguments":{"msg":"boom"}}}'
        );
        self::assertArrayNotHasKey('error', $out);
        self::assertTrue($out['result']['isError']);
        self::assertSame('bad msg', $out['result']['content'][0]['text']);
    }

    public function testUnknownToolIsInvalidParams(): void
    {
        $out = $this->handle(
            '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"nope","arguments":{}}}'
        );
        self::assertSame(ErrorCodes::INVALID_PARAMS, $out['error']['code']);
    }

    public function testUnknownMethodIsMethodNotFound(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":6,"method":"resources/list"}');
        self::assertSame(ErrorCodes::METHOD_NOT_FOUND, $out['error']['code']);
    }

    public function testPing(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":9,"method":"ping"}');
        self::assertSame([], $out['result']);
    }
}
