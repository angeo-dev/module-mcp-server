<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Protocol;

use Angeo\McpServer\Api\ToolAnnotationsInterface;
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

    /**
     * A tool implementing only ToolInterface (as third-party tools may) must
     * still work and simply advertise no annotations — proves the annotations
     * feature is not a BC break.
     */
    public function testToolWithoutAnnotationsInterfaceAdvertisesNone(): void
    {
        $out = $this->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
        self::assertArrayNotHasKey('annotations', $out['result']['tools'][0]);
        self::assertArrayNotHasKey('title', $out['result']['tools'][0]);
    }

    /**
     * A tool implementing ToolAnnotationsInterface has its hints exposed, with
     * the title lifted to the top level as well (MCP allows both positions).
     */
    public function testAnnotatedToolExposesHintsAndTitle(): void
    {
        $annotated = new class implements ToolInterface, ToolAnnotationsInterface {
            public function getName(): string { return 'destructive_tool'; }
            public function getDescription(): string { return 'Does something irreversible'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array { return []; }
            public function getAnnotations(): array
            {
                return [
                    'title'           => 'Destructive tool',
                    'readOnlyHint'    => false,
                    'destructiveHint' => true,
                    'idempotentHint'  => false,
                    'openWorldHint'   => true,
                ];
            }
        };

        $server = new McpServer(new ToolRegistry([$annotated]), $this->config);
        $response = $server->handle(
            JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":9,"method":"tools/list"}'),
            $this->store
        );
        $out = json_decode(json_encode($response->toArray()), true);

        $tool = $out['result']['tools'][0];
        self::assertSame('Destructive tool', $tool['title']);
        self::assertTrue($tool['annotations']['destructiveHint']);
        self::assertFalse($tool['annotations']['readOnlyHint']);
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
