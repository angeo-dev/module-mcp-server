<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Controller;

use Angeo\McpServer\Controller\Link\Exchange;
use Angeo\McpServer\Controller\Link\Revoke;
use Angeo\McpServer\Controller\Link\Token;
use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Linking\CustomerTokenMinter;
use Angeo\McpServer\Model\Linking\LinkStore;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../_files/GeneratedFactories.php';

/**
 * The connector-facing endpoints: who may call them, and what they reveal.
 */
class LinkApiTest extends TestCase
{
    private string $authorization = 'Bearer proxy-integration-token';
    private string $body = '';
    private bool $linkingEnabled = true;
    private int $status = 0;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<string, string> */
    private array $headers = [];
    /** @var LinkStore&MockObject */
    private LinkStore $linkStore;
    /** @var CustomerTokenMinter&MockObject */
    private CustomerTokenMinter $minter;
    private string $rateDir;

    protected function setUp(): void
    {
        $this->linkStore = $this->createMock(LinkStore::class);
        $this->minter = $this->createMock(CustomerTokenMinter::class);
        $this->rateDir = sys_get_temp_dir() . '/angeo-link-' . uniqid('', true);
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

    /** @return array<int, mixed> */
    private function deps(): array
    {
        $request = $this->getMockBuilder(HttpRequest::class)->disableOriginalConstructor()
            ->onlyMethods(['getHeader', 'getContent', 'getServer'])->getMock();
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => strtolower($name) === 'authorization' ? $this->authorization : false
        );
        $request->method('getContent')->willReturnCallback(fn () => $this->body);
        $request->method('getServer')->willReturn('198.51.100.4');

        $json = $this->getMockBuilder(Json::class)->disableOriginalConstructor()
            ->onlyMethods(['setHttpResponseCode', 'setHeader', 'setData'])->getMock();
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json) {
            $this->status = $code;
            return $json;
        });
        $json->method('setHeader')->willReturnCallback(function (string $name, $value) use ($json) {
            $this->headers[$name] = (string) $value;
            return $json;
        });
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isLinkingEnabled')->willReturnCallback(fn () => $this->linkingEnabled);
        $config->method('getLinkingClientLabel')->willReturn('Claude via angeo.dev');
        $config->method('getLinkingTokenTtl')->willReturn(3600);

        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()
            ->onlyMethods(['getWebsiteId', 'getId'])->getMock();
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $validator = $this->createStub(TokenValidator::class);
        $validator->method('consumerIdFor')->willReturnCallback(
            fn (?string $t) => $t === 'proxy-integration-token' ? 9 : null
        );

        $directory = $this->createStub(WriteInterface::class);
        $directory->method('getAbsolutePath')->willReturn($this->rateDir);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        return [$request, $jsonFactory, $config, $storeManager, $validator, $filesystem,
            $this->createStub(LoggerInterface::class)];
    }

    private function exchange(): Exchange
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('jan@example.nl');
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);

        return new Exchange(...[...$this->deps(), $this->linkStore, $this->minter, $customers]);
    }

    private function token(): Token
    {
        return new Token(...[...$this->deps(), $this->linkStore, $this->minter]);
    }

    public function testExchangeIssuesGrantAndFirstToken(): void
    {
        $this->body = json_encode(['code' => 'c', 'code_verifier' => str_repeat('v', 43), 'redirect_uri' => 'https://x/cb']);
        $this->linkStore->method('redeemCode')->willReturn(['customer_id' => 42, 'website_id' => 1]);
        $this->minter->method('mayActFor')->willReturn(true);
        $grant = 'g7.' . str_repeat('S', 43);
        $this->minter->expects(self::once())->method('mint')->with(7, 42, 1, 3600)
            ->willReturn(['token' => 'amc_first', 'expires_in' => 3540]);
        $this->linkStore->expects(self::once())->method('createGrant')
            ->with(42, 1, 9, 'Claude via angeo.dev')->willReturn($grant);

        $this->exchange()->execute();

        self::assertSame(200, $this->status);
        self::assertSame($grant, $this->data['grant']);
        self::assertSame('amc_first', $this->data['customer_token']);
        self::assertSame('j…@example.nl', $this->data['customer_label']);
        self::assertSame('no-store', $this->headers['Cache-Control']);
    }

    public function testExchangeWithBadCodeRevealsNothing(): void
    {
        $this->body = json_encode(['code' => 'c', 'code_verifier' => 'x', 'redirect_uri' => 'https://x/cb']);
        $this->linkStore->method('redeemCode')->willReturn(null);
        $this->linkStore->expects(self::never())->method('createGrant');

        $this->exchange()->execute();

        self::assertSame(400, $this->status);
        self::assertSame(['error' => 'invalid_grant'], $this->data);
    }

    public function testCodeFromAnotherWebsiteIsRefused(): void
    {
        $this->body = json_encode(['code' => 'c', 'code_verifier' => str_repeat('v', 43), 'redirect_uri' => 'https://x/cb']);
        $this->linkStore->method('redeemCode')->willReturn(['customer_id' => 42, 'website_id' => 2]);
        $this->linkStore->expects(self::never())->method('createGrant');

        $this->exchange()->execute();

        self::assertSame(400, $this->status);
    }

    public function testLockedCustomerGetsNoGrant(): void
    {
        $this->body = json_encode(['code' => 'c', 'code_verifier' => str_repeat('v', 43), 'redirect_uri' => 'https://x/cb']);
        $this->linkStore->method('redeemCode')->willReturn(['customer_id' => 42, 'website_id' => 1]);
        $this->minter->method('mayActFor')->willReturn(false);
        $this->linkStore->expects(self::never())->method('createGrant');

        $this->exchange()->execute();

        self::assertSame(400, $this->status);
    }

    public function testCallerWithoutIntegrationTokenIsRefused(): void
    {
        $this->authorization = 'Bearer customer-token-is-not-enough';
        $this->body = '{"grant":"g7.x"}';
        $this->linkStore->expects(self::never())->method('findActiveGrant');

        $this->token()->execute();

        self::assertSame(401, $this->status);
        self::assertSame(['error' => 'invalid_client'], $this->data);
    }

    public function testDisabledLinkingLooksLikeNoEndpoint(): void
    {
        $this->linkingEnabled = false;

        $this->token()->execute();

        self::assertSame(404, $this->status);
    }

    public function testMalformedBodyIsInvalidRequest(): void
    {
        $this->body = 'not json';

        $this->token()->execute();

        self::assertSame(400, $this->status);
        self::assertSame(['error' => 'invalid_request'], $this->data);
    }

    public function testTokenRefreshForActiveGrant(): void
    {
        $this->body = '{"grant":"g7.secret"}';
        $this->linkStore->method('findActiveGrant')->with('g7.secret', 9)
            ->willReturn(['grant_id' => 7, 'customer_id' => 42, 'website_id' => 1]);
        $this->minter->method('mint')->with(7, 42, 1, 3600)->willReturn(['token' => 'fresh', 'expires_in' => 3540]);
        $this->linkStore->expects(self::once())->method('touchGrant')->with(7);

        $this->token()->execute();

        self::assertSame(['customer_token' => 'fresh', 'expires_in' => 3540], $this->data);
    }

    public function testRevokedGrantGetsNoToken(): void
    {
        $this->body = '{"grant":"g7.secret"}';
        $this->linkStore->method('findActiveGrant')->willReturn(null);
        $this->minter->expects(self::never())->method('mint');

        $this->token()->execute();

        self::assertSame(['error' => 'invalid_grant'], $this->data);
    }

    public function testRevokeNeverRevealsWhetherAGrantExisted(): void
    {
        $this->body = '{"grant":"g999.unknown"}';
        $this->linkStore->method('revokeByConsumer')->willReturn(false);

        (new Revoke(...[...$this->deps(), $this->linkStore]))->execute();

        self::assertSame(200, $this->status);
        self::assertSame(['ok' => true], $this->data);
    }
}
