<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Security;

use Angeo\McpServer\Model\Linking\CustomerTokenMinter;
use Angeo\McpServer\Model\Linking\LinkStore;
use Angeo\McpServer\Model\Security\CustomerTokenResolver;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Api\Data\UserTokenDataInterface;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Api\UserTokenValidatorInterface;
use PHPUnit\Framework\TestCase;

class CustomerTokenResolverTest extends TestCase
{
    private function userToken(int $userId, int $userType): UserToken
    {
        $context = $this->createStub(UserContextInterface::class);
        $context->method('getUserId')->willReturn($userId);
        $context->method('getUserType')->willReturn($userType);

        return new UserToken($context, $this->createStub(UserTokenDataInterface::class));
    }

    private function resolver(?UserToken $token, bool $valid = true): CustomerTokenResolver
    {
        $reader = $this->createMock(UserTokenReaderInterface::class);
        if ($token === null) {
            $reader->method('read')->willThrowException(new UserTokenException('Token does not exist'));
        } else {
            $reader->method('read')->willReturn($token);
        }

        $validator = $this->createMock(UserTokenValidatorInterface::class);
        if (!$valid) {
            $validator->method('validate')->willThrowException(
                new AuthorizationException(__('Token has expired'))
            );
        }

        return new CustomerTokenResolver($reader, $validator, $this->createStub(LinkStore::class), $this->createStub(CustomerTokenMinter::class));
    }

    public function testValidCustomerTokenResolvesToCustomerId(): void
    {
        $resolver = $this->resolver($this->userToken(42, UserContextInterface::USER_TYPE_CUSTOMER));
        self::assertSame(42, $resolver->resolve('jwt'));
    }

    public function testExpiredTokenResolvesToNull(): void
    {
        $resolver = $this->resolver($this->userToken(42, UserContextInterface::USER_TYPE_CUSTOMER), false);
        self::assertNull($resolver->resolve('jwt'));
    }

    public function testAdminTokenIsNotACustomer(): void
    {
        $resolver = $this->resolver($this->userToken(1, UserContextInterface::USER_TYPE_ADMIN));
        self::assertNull($resolver->resolve('jwt'));
    }

    public function testIntegrationTokenIsNotACustomer(): void
    {
        $resolver = $this->resolver($this->userToken(3, UserContextInterface::USER_TYPE_INTEGRATION));
        self::assertNull($resolver->resolve('jwt'));
    }

    public function testUnreadableTokenResolvesToNull(): void
    {
        self::assertNull($this->resolver(null)->resolve('garbage'));
    }

    public function testOversizedTokenIsRefusedBeforeParsing(): void
    {
        $reader = $this->createMock(UserTokenReaderInterface::class);
        $reader->expects(self::never())->method('read');
        $resolver = new CustomerTokenResolver(
            $reader,
            $this->createStub(UserTokenValidatorInterface::class),
            $this->createStub(LinkStore::class),
            $this->createStub(CustomerTokenMinter::class)
        );

        self::assertNull($resolver->resolve(str_repeat('a', 5000)));
        self::assertNull($resolver->resolve(''));
        self::assertNull($resolver->resolve(null));
    }

    // ── MCP-only tokens (account linking) ──────────────────────────────────

    private function mcpResolver(?array $grant, bool $mayAct = true): CustomerTokenResolver
    {
        $store = $this->createStub(LinkStore::class);
        $store->method('resolveAccessToken')->willReturn($grant);
        $minter = $this->createStub(CustomerTokenMinter::class);
        $minter->method('mayActFor')->willReturn($mayAct);
        $reader = $this->createMock(UserTokenReaderInterface::class);
        $reader->expects(self::never())->method('read');

        return new CustomerTokenResolver($reader, $this->createStub(UserTokenValidatorInterface::class), $store, $minter);
    }

    public function testMcpTokenResolvesThroughItsGrantNotMagentoTokenServices(): void
    {
        $resolver = $this->mcpResolver(['grant_id' => 7, 'customer_id' => 42, 'website_id' => 1]);
        self::assertSame(42, $resolver->resolveMcpToken('amc_x', 9, 1));
    }

    public function testMcpTokenForAnotherWebsiteIsRefused(): void
    {
        $resolver = $this->mcpResolver(['grant_id' => 7, 'customer_id' => 42, 'website_id' => 2]);
        self::assertNull($resolver->resolveMcpToken('amc_x', 9, 1));
    }

    public function testMcpTokenOfLockedCustomerIsRefused(): void
    {
        $resolver = $this->mcpResolver(['grant_id' => 7, 'customer_id' => 42, 'website_id' => 1], false);
        self::assertNull($resolver->resolveMcpToken('amc_x', 9, 1));
    }

    public function testUnknownMcpTokenIsRefused(): void
    {
        self::assertNull($this->mcpResolver(null)->resolveMcpToken('amc_x', 9, 1));
    }

    public function testPrefixTellsTokensApart(): void
    {
        self::assertTrue(CustomerTokenResolver::isMcpToken('amc_' . str_repeat('A', 43)));
        self::assertFalse(CustomerTokenResolver::isMcpToken('eyJhbGciOiJIUzI1NiJ9.x.y'));
    }
}
