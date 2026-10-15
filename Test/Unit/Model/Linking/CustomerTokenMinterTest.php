<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Linking;

use Angeo\McpServer\Model\Linking\CustomerTokenMinter;
use Angeo\McpServer\Model\Linking\LinkStore;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class CustomerTokenMinterTest extends TestCase
{
    /** @var list<array{int, int}> */
    private array $issued = [];

    private function minter(?int $customerWebsite = 1, bool $locked = false, bool $websiteScope = true): CustomerTokenMinter
    {
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        if ($customerWebsite === null) {
            $customers->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        } else {
            $customer = $this->createStub(CustomerInterface::class);
            $customer->method('getWebsiteId')->willReturn($customerWebsite);
            $customers->method('getById')->willReturn($customer);
        }

        $store = $this->createMock(LinkStore::class);
        $store->method('issueAccessToken')->willReturnCallback(function (int $grantId, int $ttl): string {
            $this->issued[] = [$grantId, $ttl];
            return 'amc_' . str_repeat('A', 43);
        });

        $auth = $this->createStub(AuthenticationInterface::class);
        $auth->method('isLocked')->willReturn($locked);
        $share = $this->createStub(Share::class);
        $share->method('isWebsiteScope')->willReturn($websiteScope);

        return new CustomerTokenMinter($store, $customers, $auth, $share);
    }

    public function testIssuesAnMcpOnlyTokenForTheGrant(): void
    {
        $out = $this->minter()->mint(7, 42, 1, 3600);

        self::assertStringStartsWith('amc_', $out['token']);
        self::assertSame(3540, $out['expires_in']);
        self::assertSame([[7, 3600]], $this->issued);
    }

    public function testLifetimeIsClamped(): void
    {
        $this->minter()->mint(7, 42, 1, 10);
        $this->minter()->mint(7, 42, 1, 999999);

        self::assertSame([[7, 300], [7, 86400]], $this->issued);
    }

    public function testDeletedCustomerGetsNothing(): void
    {
        self::assertNull($this->minter(null)->mint(7, 42, 1));
        self::assertSame([], $this->issued);
    }

    public function testLockedCustomerGetsNothing(): void
    {
        self::assertNull($this->minter(1, true)->mint(7, 42, 1));
    }

    public function testCustomerOfAnotherWebsiteGetsNothing(): void
    {
        self::assertNull($this->minter(2)->mint(7, 42, 1));
    }

    public function testGlobalAccountsMayCrossWebsites(): void
    {
        self::assertNotNull($this->minter(2, false, false)->mint(7, 42, 1));
    }
}
