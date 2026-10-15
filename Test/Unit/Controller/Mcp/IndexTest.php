<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Controller\Mcp;

use Angeo\McpServer\Api\ToolInterface;
use Angeo\McpServer\Controller\Mcp\Index;
use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Log\AgentCallLogger;
use Angeo\McpServer\Model\Protocol\ErrorCodes;
use Angeo\McpServer\Model\Protocol\McpServer;
use Angeo\McpServer\Model\Protocol\ProtocolNegotiator;
use Angeo\McpServer\Model\Security\CallerContext;
use Angeo\McpServer\Model\Security\CustomerTokenResolver;
use Angeo\McpServer\Model\Security\TokenValidator;
use Angeo\McpServer\Model\Tool\ToolRegistry;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The /mcp request pipeline: method, Origin, authentication, protocol era,
 * and the HTTP status codes each outcome maps to.
 */
class IndexTest extends TestCase
{
    /** @var array<string, string> */
    private array $headers = [];
    private string $httpMethod = 'POST';
    private string $body = '';

    private int $status = 0;
    private string $responseBody = '';
    /** @var array<string, string> */
    private array $responseHeaders = [];

    private bool $tokenRequired = false;
    private bool $customerAuth = false;
    private bool $storefrontTokens = false;
    /** @var array<string, array{int, int}> MCP token => [consumer id, customer id] */
    private array $mcpTokens = [];
    private bool $strictHeaders = true;
    /** @var string[] */
    private array $allowedOrigins = [];
    private ?string $validIntegrationToken = null;
    /** @var array<string, int> customer token => customer id */
    private array $customerTokens = [];

    private CallerContext $caller;
    private ?int $customerSeenByTool = null;
    private string $rateDir;

    protected function setUp(): void
    {
        $this->caller = new CallerContext();
        $this->rateDir = sys_get_temp_dir() . '/angeo-mcp-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->rateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->rateDir)) {
            rmdir($this->rateDir);
        }
    }

    private function controller(): Index
    {
        $request = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getHeader', 'getContent', 'getMethod', 'getServer'])
            ->getMock();
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => $this->headers[strtolower($name)] ?? false
        );
        $request->method('getContent')->willReturnCallback(fn () => $this->body);
        $request->method('getMethod')->willReturnCallback(fn () => $this->httpMethod);
        $request->method('getServer')->willReturn('203.0.113.7');

        $response = $this->getMockBuilder(HttpResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHeader', 'setHttpResponseCode', 'setBody'])
            ->getMock();
        $response->method('setHeader')->willReturnCallback(function (string $name, $value) use (&$response) {
            $this->responseHeaders[strtolower($name)] = (string) $value;
            return $response;
        });
        $response->method('setHttpResponseCode')->willReturnCallback(function (int $code) use (&$response) {
            $this->status = $code;
            return $response;
        });
        $response->method('setBody')->willReturnCallback(function ($body) use (&$response) {
            $this->responseBody = (string) $body;
            return $response;
        });

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl', 'getId', 'getCode', 'getName'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $store->method('getName')->willReturn('Acme Outdoor');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getRateLimitPerMinute')->willReturn(0);
        $config->method('isTokenRequired')->willReturnCallback(fn () => $this->tokenRequired);
        $config->method('isCustomerAuthEnabled')->willReturnCallback(fn () => $this->customerAuth);
        $config->method('isStorefrontCustomerTokenAllowed')->willReturnCallback(fn () => $this->storefrontTokens);
        $config->method('isStrictHeaders')->willReturnCallback(fn () => $this->strictHeaders);
        $config->method('getAllowedOrigins')->willReturnCallback(fn () => $this->allowedOrigins);
        $config->method('isCallLoggingEnabled')->willReturn(false);
        $config->method('getListTtlMs')->willReturn(300000);
        $config->method('getStoreLabel')->willReturn('Acme Outdoor');

        $tokenValidator = $this->createStub(TokenValidator::class);
        $tokenValidator->method('consumerIdFor')->willReturnCallback(
            fn (?string $token) => $token !== null && $token === $this->validIntegrationToken ? 9 : null
        );

        $resolver = $this->createStub(CustomerTokenResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            fn (?string $token) => $token !== null ? ($this->customerTokens[$token] ?? null) : null
        );
        $resolver->method('resolveMcpToken')->willReturnCallback(
            function (string $token, int $consumerId) {
                [$owner, $customer] = $this->mcpTokens[$token] ?? [0, 0];
                return $owner === $consumerId ? $customer : null;
            }
        );

        $directory = $this->createStub(WriteInterface::class);
        $directory->method('getAbsolutePath')->willReturn($this->rateDir);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        $caller = $this->caller;
        $whoAmI = new class ($caller, $this) implements ToolInterface {
            public function __construct(private CallerContext $caller, private IndexTest $test)
            {
            }
            public function getName(): string { return 'who_am_i'; }
            public function getDescription(): string { return 'Report the caller.'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function isAvailable(StoreInterface $store): bool { return true; }
            public function execute(array $arguments, StoreInterface $store): array
            {
                $this->test->recordCustomer($this->caller->getCustomerId());
                return ['auth' => $this->caller->getAuthType()];
            }
        };

        $server = new McpServer(new ToolRegistry([$whoAmI]), $config, null, $this->caller);

        return new Index(
            $request,
            $response,
            $storeManager,
            $config,
            $server,
            $tokenValidator,
            $filesystem,
            $this->createStub(AgentCallLogger::class),
            $this->createStub(LoggerInterface::class),
            new ProtocolNegotiator(),
            $this->caller,
            $resolver
        );
    }

    public function recordCustomer(?int $customerId): void
    {
        $this->customerSeenByTool = $customerId;
    }

    /** @return array<string, mixed> */
    private function dispatch(): array
    {
        $this->controller()->execute();
        $decoded = json_decode($this->responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function legacyCall(string $tool = 'who_am_i'): void
    {
        $this->body = json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => new \stdClass()],
        ]);
    }

    private function modernCall(string $method = 'tools/call', ?string $tool = 'who_am_i'): void
    {
        $params = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
        if ($tool !== null) {
            $params['name'] = $tool;
            $params['arguments'] = new \stdClass();
        }
        $this->body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $this->headers['mcp-protocol-version'] = '2026-07-28';
        $this->headers['mcp-method'] = $method;
        if ($tool !== null) {
            $this->headers['mcp-name'] = $tool;
        }
    }

    // ── HTTP method ─────────────────────────────────────────────────────────

    public function testGetIsMethodNotAllowed(): void
    {
        $this->httpMethod = 'GET';
        $out = $this->dispatch();

        self::assertSame(405, $this->status);
        self::assertSame('POST', $this->responseHeaders['allow']);
        self::assertSame(ErrorCodes::INVALID_REQUEST, $out['error']['code']);
    }

    public function testDeleteIsMethodNotAllowed(): void
    {
        $this->httpMethod = 'DELETE';
        $this->dispatch();

        self::assertSame(405, $this->status);
    }

    // ── Origin ──────────────────────────────────────────────────────────────

    public function testServerToServerRequestWithoutOriginIsAllowed(): void
    {
        $this->legacyCall();
        $this->dispatch();

        self::assertSame(200, $this->status);
    }

    public function testForeignBrowserOriginIsForbidden(): void
    {
        $this->legacyCall();
        $this->headers['origin'] = 'https://evil.example.net';
        $this->dispatch();

        self::assertSame(403, $this->status);
    }

    public function testLiteralNullOriginIsForbidden(): void
    {
        $this->legacyCall();
        $this->headers['origin'] = 'null';
        $this->dispatch();

        self::assertSame(403, $this->status);
    }

    public function testStoreOwnOriginIsAllowed(): void
    {
        $this->legacyCall();
        $this->headers['origin'] = 'https://SHOP.example.com';
        $this->dispatch();

        self::assertSame(200, $this->status);
    }

    public function testConfiguredOriginIsAllowed(): void
    {
        $this->legacyCall();
        $this->allowedOrigins = ['http://localhost:6274'];
        $this->headers['origin'] = 'http://localhost:6274';
        $this->dispatch();

        self::assertSame(200, $this->status);
    }

    // ── Authentication ──────────────────────────────────────────────────────

    public function testRequiredTokenRejectsAnonymousCaller(): void
    {
        $this->legacyCall();
        $this->tokenRequired = true;
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertSame('Bearer', $this->responseHeaders['www-authenticate']);
    }

    public function testRequiredTokenIsNotSatisfiedByCustomerToken(): void
    {
        $this->legacyCall();
        $this->tokenRequired = true;
        $this->customerAuth = true;
        $this->customerTokens = ['cust-jwt' => 42];
        $this->headers['authorization'] = 'Bearer cust-jwt';
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertNull($this->customerSeenByTool);
    }

    public function testCustomerBearerIsIgnoredWhileCustomerAuthIsOff(): void
    {
        $this->legacyCall();
        $this->customerTokens = ['cust-jwt' => 42];
        $this->headers['authorization'] = 'Bearer cust-jwt';
        $out = $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame('anonymous', $out['result']['structuredContent']['auth']);
        self::assertNull($this->customerSeenByTool);
    }

    public function testCustomerBearerAuthenticatesTheShopperInTestingMode(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->storefrontTokens = true;
        $this->customerTokens = ['cust-jwt' => 42];
        $this->headers['authorization'] = 'Bearer cust-jwt';
        $out = $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame('customer', $out['result']['structuredContent']['auth']);
        self::assertSame(42, $this->customerSeenByTool);
    }

    public function testProxyCanActForShopperWithAnMcpToken(): void
    {
        $this->legacyCall();
        $this->tokenRequired = true;
        $this->customerAuth = true;
        $this->validIntegrationToken = 'proxy-token';
        $this->mcpTokens = ['amc_linked' => [9, 42]];
        $this->headers['authorization'] = 'Bearer proxy-token';
        $this->headers['x-customer-token'] = 'amc_linked';
        $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame(42, $this->customerSeenByTool);
        self::assertTrue($this->caller->hasIntegration());
    }

    /**
     * MCP spec: a server MUST NOT accept tokens not issued for it. A storefront
     * (REST) customer token is not an MCP token, so by default it opens nothing.
     */
    public function testStorefrontCustomerTokenIsRefusedByDefault(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->customerTokens = ['cust-jwt' => 42];
        $this->headers['authorization'] = 'Bearer cust-jwt';
        $out = $this->dispatch();

        self::assertSame('anonymous', $out['result']['structuredContent']['auth']);
        self::assertNull($this->customerSeenByTool);
    }

    public function testStorefrontTokenInCustomerHeaderIsRefusedByDefault(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->validIntegrationToken = 'proxy-token';
        $this->customerTokens = ['cust-jwt' => 42];
        $this->headers['authorization'] = 'Bearer proxy-token';
        $this->headers['x-customer-token'] = 'cust-jwt';
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertNull($this->customerSeenByTool);
    }

    /** A stolen MCP token alone opens nothing: it needs its own proxy's Integration token. */
    public function testMcpTokenWithoutIntegrationIsRefused(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->mcpTokens = ['amc_linked' => [9, 42]];
        $this->headers['x-customer-token'] = 'amc_linked';
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertNull($this->customerSeenByTool);
    }

    public function testMcpTokenOfAnotherIntegrationIsRefused(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->validIntegrationToken = 'proxy-token';           // consumer 9
        $this->mcpTokens = ['amc_other' => [10, 42]];           // held by consumer 10
        $this->headers['authorization'] = 'Bearer proxy-token';
        $this->headers['x-customer-token'] = 'amc_other';
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertNull($this->customerSeenByTool);
    }

    public function testMcpTokenAsBearerIsNeverACustomer(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->storefrontTokens = true;
        $this->mcpTokens = ['amc_linked' => [9, 42]];
        $this->headers['authorization'] = 'Bearer amc_linked';
        $out = $this->dispatch();

        self::assertSame('anonymous', $out['result']['structuredContent']['auth']);
    }

    public function testInvalidExplicitCustomerTokenFailsClosed(): void
    {
        $this->legacyCall();
        $this->customerAuth = true;
        $this->validIntegrationToken = 'proxy-token';
        $this->headers['authorization'] = 'Bearer proxy-token';
        $this->headers['x-customer-token'] = 'expired';
        $this->dispatch();

        self::assertSame(401, $this->status);
        self::assertNull($this->customerSeenByTool);
    }

    public function testUnknownBearerStaysAnonymousWhenTokenNotRequired(): void
    {
        // 2.2.x behaviour, kept: an unrecognised Bearer on an open endpoint is
        // not an error (an MCP proxy may forward its own token).
        $this->legacyCall();
        $this->customerAuth = true;
        $this->headers['authorization'] = 'Bearer something-else';
        $out = $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame('anonymous', $out['result']['structuredContent']['auth']);
    }

    public function testCallerIsResetBetweenRequests(): void
    {
        $this->caller->authenticate(99, true);
        $this->legacyCall();
        $out = $this->dispatch();

        self::assertSame('anonymous', $out['result']['structuredContent']['auth']);
    }

    // ── Protocol era ────────────────────────────────────────────────────────

    public function testModernToolCallSucceeds(): void
    {
        $this->modernCall();
        $out = $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame('complete', $out['result']['resultType']);
    }

    public function testModernUnknownMethodIs404(): void
    {
        $this->modernCall('resources/list', null);
        $out = $this->dispatch();

        self::assertSame(404, $this->status);
        self::assertSame(ErrorCodes::METHOD_NOT_FOUND, $out['error']['code']);
    }

    public function testLegacyUnknownMethodStays200(): void
    {
        $this->body = '{"jsonrpc":"2.0","id":1,"method":"resources/list"}';
        $out = $this->dispatch();

        self::assertSame(200, $this->status);
        self::assertSame(ErrorCodes::METHOD_NOT_FOUND, $out['error']['code']);
    }

    public function testHeaderMismatchIs400(): void
    {
        $this->modernCall();
        $this->headers['mcp-name'] = 'search_products';
        $out = $this->dispatch();

        self::assertSame(400, $this->status);
        self::assertSame(ErrorCodes::HEADER_MISMATCH, $out['error']['code']);
        self::assertNull($this->customerSeenByTool, 'the tool must not run');
    }

    public function testUnsupportedVersionIs400WithSupportedList(): void
    {
        $this->modernCall();
        $this->body = str_replace('2026-07-28', '2030-01-01', $this->body);
        $this->headers['mcp-protocol-version'] = '2030-01-01';
        $out = $this->dispatch();

        self::assertSame(400, $this->status);
        self::assertSame(ErrorCodes::UNSUPPORTED_PROTOCOL_VERSION, $out['error']['code']);
        self::assertContains('2026-07-28', $out['error']['data']['supported']);
    }

    public function testNotificationIs202WithEmptyBody(): void
    {
        $this->body = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
        $this->dispatch();

        self::assertSame(202, $this->status);
        self::assertSame('', $this->responseBody);
    }

    public function testResponsesAreNeverCached(): void
    {
        $this->legacyCall();
        $this->dispatch();

        self::assertSame('no-store', $this->responseHeaders['cache-control']);
        self::assertStringContainsString('Authorization', $this->responseHeaders['vary']);
    }
}
