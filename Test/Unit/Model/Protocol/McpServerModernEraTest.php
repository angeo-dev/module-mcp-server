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
use Angeo\McpServer\Model\Security\CallerContext;
use Angeo\McpServer\Model\Tool\ToolRegistry;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * MCP 2026-07-28 behaviour of the dispatcher, plus the legacy behaviour it
 * must leave untouched.
 */
class McpServerModernEraTest extends TestCase
{
    private const MODERN = '2026-07-28';

    private StoreInterface $store;
    private CallerContext $caller;
    private McpServer $server;

    protected function setUp(): void
    {
        $tool = new class implements ToolInterface {
            public function getName(): string { return 'echo_tool'; }
            public function getDescription(): string { return 'Echo'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array
            {
                if (($arguments['fail'] ?? false) === true) {
                    throw new \InvalidArgumentException('nope');
                }
                return ['ok' => true];
            }
        };

        $this->store = $this->createStub(StoreInterface::class);
        $this->caller = new CallerContext();
        $this->server = new McpServer(new ToolRegistry([$tool]), null, null, $this->caller);
    }

    /** @return array<string, mixed>|null */
    private function call(string $method, array $params = [], ?string $version = self::MODERN): ?array
    {
        $request = JsonRpcRequest::fromJson(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 7,
            'method'  => $method,
            'params'  => $params === [] ? new \stdClass() : $params,
        ]));
        return $this->server->handle($request, $this->store, $version)?->toArray();
    }

    public function testDiscoverAdvertisesBothEras(): void
    {
        $result = $this->call('server/discover')['result'];

        self::assertSame('complete', $result['resultType']);
        self::assertSame('2026-07-28', $result['supportedVersions'][0]);
        self::assertContains('2025-11-25', $result['supportedVersions']);
        self::assertContains('2024-11-05', $result['supportedVersions']);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertNotSame('', $result['instructions']);
        self::assertSame(McpServer::SERVER_NAME, $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertSame(300000, $result['ttlMs']);
        self::assertSame('public', $result['cacheScope']);
    }

    public function testDiscoverAlsoAnswersLegacyClients(): void
    {
        $result = $this->call('server/discover', [], null)['result'];

        self::assertContains('2026-07-28', $result['supportedVersions']);
    }

    public function testModernListResultIsCacheable(): void
    {
        $result = $this->call('tools/list')['result'];

        self::assertSame('complete', $result['resultType']);
        self::assertSame(300000, $result['ttlMs']);
        self::assertSame('public', $result['cacheScope']);
        self::assertSame(McpServer::SERVER_VERSION, $result['_meta']['io.modelcontextprotocol/serverInfo']['version']);
        self::assertSame('echo_tool', $result['tools'][0]['name']);
    }

    public function testAuthenticatedListIsPrivate(): void
    {
        $this->caller->authenticate(42, false);

        self::assertSame('private', $this->call('tools/list')['result']['cacheScope']);
    }

    public function testIntegrationListIsPrivate(): void
    {
        $this->caller->authenticate(null, true);

        self::assertSame('private', $this->call('tools/list')['result']['cacheScope']);
    }

    public function testModernToolCallCarriesResultType(): void
    {
        $result = $this->call('tools/call', ['name' => 'echo_tool', 'arguments' => []])['result'];

        self::assertSame('complete', $result['resultType']);
        self::assertFalse($result['isError']);
        self::assertSame(['ok' => true], $result['structuredContent']);
    }

    public function testModernToolErrorStaysAToolResult(): void
    {
        $out = $this->call('tools/call', ['name' => 'echo_tool', 'arguments' => ['fail' => true]]);

        self::assertArrayNotHasKey('error', $out);
        self::assertTrue($out['result']['isError']);
        self::assertSame('complete', $out['result']['resultType']);
    }

    public function testInitializeIsNotAModernMethod(): void
    {
        $out = $this->call('initialize', ['protocolVersion' => '2025-11-25']);

        self::assertSame(ErrorCodes::METHOD_NOT_FOUND, $out['error']['code']);
    }

    public function testModernErrorsGetNoResultFields(): void
    {
        $out = $this->call('resources/list');

        self::assertSame(ErrorCodes::METHOD_NOT_FOUND, $out['error']['code']);
        self::assertArrayNotHasKey('result', $out);
    }

    public function testLegacyResultsAreUnchanged(): void
    {
        $result = $this->call('tools/list', [], null)['result'];

        self::assertArrayNotHasKey('resultType', $result);
        self::assertArrayNotHasKey('ttlMs', $result);
        self::assertArrayNotHasKey('_meta', $result);
    }

    public function testInitializeNegotiates20251125(): void
    {
        $result = $this->call('initialize', ['protocolVersion' => '2025-11-25'], null)['result'];

        self::assertSame('2025-11-25', $result['protocolVersion']);
    }

    public function testInitializeNeverAnswersWithTheStatelessVersion(): void
    {
        // A handshake that ends in "2026-07-28" would be a contradiction:
        // that revision has no handshake.
        $result = $this->call('initialize', ['protocolVersion' => '2026-07-28'], null)['result'];

        self::assertSame(McpServer::PROTOCOL_VERSION, $result['protocolVersion']);
        self::assertNotSame('2026-07-28', $result['protocolVersion']);
    }

    public function testInstructionsSaySignedInOnlyForVerifiedCustomer(): void
    {
        $anonymous = $this->call('server/discover')['result']['instructions'];
        self::assertStringNotContainsString('signed in', $anonymous);

        $this->caller->authenticate(42, false);
        $customer = $this->call('server/discover')['result']['instructions'];
        self::assertStringContainsString('signed in', $customer);
        self::assertStringNotContainsString('not-logged-in', $customer);
    }

    public function testThirdPartyToolGetsTopLevelTitleFromItsAnnotations(): void
    {
        $tool = new class implements ToolInterface, \Angeo\McpServer\Api\ToolAnnotationsInterface {
            public function getName(): string { return 'list_my_orders'; }
            public function getDescription(): string { return 'Orders'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array { return []; }
            public function getAnnotations(): array { return ['title' => 'My orders', 'readOnlyHint' => true]; }
        };
        $server = new McpServer(new ToolRegistry([$tool]));
        $out = $server->handle(
            JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'),
            $this->store
        )?->toArray();

        self::assertSame('My orders', $out['result']['tools'][0]['title']);
    }
}
