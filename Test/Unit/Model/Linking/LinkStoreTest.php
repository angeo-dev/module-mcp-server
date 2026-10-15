<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Linking;

use Angeo\McpServer\Model\Linking\LinkStore;
use Angeo\McpServer\Model\Linking\Pkce;
use Angeo\McpServer\Model\Linking\Secret;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinkStoreTest extends TestCase
{
    private const REDIRECT = 'https://mcp.angeo.dev/authorize/linked';

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;
    private ?array $row = null;
    private int $updateResult = 1;
    /** @var list<array{string, array<string, mixed>, mixed}> */
    private array $updates = [];
    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    private function store(): LinkStore
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('join')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnCallback(fn () => $this->row ?? false);
        $this->connection->method('fetchOne')->willReturn('7');
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->inserts[] = $data;
            return 1;
        });
        $this->connection->method('update')->willReturnCallback(function (string $table, array $data, $where): int {
            $this->updates[] = [$table, $data, $where];
            return $this->updateResult;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new LinkStore($resource);
    }

    /** @return array<string, mixed> */
    private static function codeRow(string $verifier, array $override = []): array
    {
        return $override + [
            'customer_id'    => '42',
            'website_id'     => '1',
            'redirect_uri'   => self::REDIRECT,
            'code_challenge' => Pkce::challengeFor($verifier),
            'expires_at'     => gmdate('Y-m-d H:i:s', time() + 60),
            'used'           => '0',
        ];
    }

    // ── Codes ───────────────────────────────────────────────────────────────

    public function testCreatedCodeIsStoredOnlyAsHash(): void
    {
        $code = $this->store()->createCode(42, 1, self::REDIRECT, Pkce::challengeFor(str_repeat('v', 43)));

        self::assertSame(Secret::hash($code), $this->inserts[0]['code_hash']);
        self::assertNotContains($code, $this->inserts[0]);
    }

    public function testValidCodeRedeemsOnce(): void
    {
        $verifier = str_repeat('v', 43);
        $store = $this->store();
        $this->row = self::codeRow($verifier);

        self::assertSame(['customer_id' => 42, 'website_id' => 1], $store->redeemCode('c', self::REDIRECT, $verifier));
        self::assertSame(['used' => 1], $this->updates[0][1]);
        self::assertArrayHasKey('used = ?', $this->updates[0][2], 'the claim must be conditional on used = 0');
    }

    public function testConcurrentSecondRedemptionLoses(): void
    {
        $verifier = str_repeat('v', 43);
        $store = $this->store();
        $this->row = self::codeRow($verifier);
        $this->updateResult = 0; // the other request claimed it first

        self::assertNull($store->redeemCode('c', self::REDIRECT, $verifier));
    }

    public function testWrongVerifierBurnsTheCode(): void
    {
        $store = $this->store();
        $this->row = self::codeRow(str_repeat('v', 43));

        self::assertNull($store->redeemCode('c', self::REDIRECT, str_repeat('w', 43)));
        self::assertCount(1, $this->updates, 'a wrong guess must not leave the code usable');
    }

    /**
     * @dataProvider unusableCodes
     */
    public function testUnusableCodesAreRefusedWithoutBurning(array $override, string $redirect): void
    {
        $verifier = str_repeat('v', 43);
        $store = $this->store();
        $this->row = self::codeRow($verifier, $override);

        self::assertNull($store->redeemCode('c', $redirect, $verifier));
        self::assertSame([], $this->updates);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unusableCodes(): array
    {
        return [
            'used'           => [['used' => '1'], self::REDIRECT],
            'expired'        => [['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], self::REDIRECT],
            'other redirect' => [[], 'https://evil.example/cb'],
        ];
    }

    public function testUnknownCodeIsRefused(): void
    {
        self::assertNull($this->store()->redeemCode('nope', self::REDIRECT, str_repeat('v', 43)));
    }

    // ── Grants ──────────────────────────────────────────────────────────────

    public function testCreatedGrantIsStoredOnlyAsHash(): void
    {
        $grant = $this->store()->createGrant(42, 1, 9, 'Claude via angeo.dev');

        $parsed = Secret::parseGrant($grant);
        self::assertSame(7, $parsed['id']);
        self::assertSame(Secret::hash($parsed['secret']), $this->inserts[0]['secret_hash']);
        self::assertSame(9, $this->inserts[0]['consumer_id']);
    }

    /** @return array{string, array<string, mixed>} */
    private static function grantFixture(array $override = []): array
    {
        $secret = str_repeat('S', 43);
        return [Secret::formatGrant(7, $secret), $override + [
            'grant_id'    => '7',
            'customer_id' => '42',
            'website_id'  => '1',
            'consumer_id' => '9',
            'secret_hash' => Secret::hash($secret),
            'revoked_at'  => null,
        ]];
    }

    public function testActiveGrantIsFound(): void
    {
        [$grant, $row] = self::grantFixture();
        $store = $this->store();
        $this->row = $row;

        self::assertSame(['grant_id' => 7, 'customer_id' => 42, 'website_id' => 1], $store->findActiveGrant($grant, 9));
    }

    public function testGrantOfAnotherIntegrationIsUnknown(): void
    {
        [$grant, $row] = self::grantFixture();
        $store = $this->store();
        $this->row = $row;

        self::assertNull($store->findActiveGrant($grant, 10));
    }

    public function testRevokedGrantIsUnknown(): void
    {
        [$grant, $row] = self::grantFixture(['revoked_at' => '2026-10-01 10:00:00']);
        $store = $this->store();
        $this->row = $row;

        self::assertNull($store->findActiveGrant($grant, 9));
    }

    public function testWrongSecretIsUnknown(): void
    {
        [, $row] = self::grantFixture();
        $store = $this->store();
        $this->row = $row;

        self::assertNull($store->findActiveGrant(Secret::formatGrant(7, str_repeat('X', 43)), 9));
    }

    public function testCustomerCanOnlyRevokeOwnGrant(): void
    {
        $this->store()->revoke(7, 42);

        $where = $this->updates[0][2];
        self::assertSame(7, $where['grant_id = ?']);
        self::assertSame(42, $where['customer_id = ?']);
    }

    // ── MCP-only tokens ─────────────────────────────────────────────────────

    public function testIssuedTokenIsPrefixedAndStoredOnlyAsHash(): void
    {
        $token = $this->store()->issueAccessToken(7, 3600);

        self::assertMatchesRegularExpression('/^amc_[A-Za-z0-9\-_]{43}$/', $token);
        self::assertSame(Secret::hash($token), $this->inserts[0]['token_hash']);
        self::assertSame(7, $this->inserts[0]['grant_id']);
    }

    /** @return array<string, mixed> */
    private static function tokenRow(array $override = []): array
    {
        return $override + [
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + 600),
            'grant_id'    => '7',
            'customer_id' => '42',
            'website_id'  => '1',
            'consumer_id' => '9',
            'revoked_at'  => null,
        ];
    }

    public function testValidTokenResolvesToItsGrant(): void
    {
        $store = $this->store();
        $this->row = self::tokenRow();

        self::assertSame(
            ['grant_id' => 7, 'customer_id' => 42, 'website_id' => 1],
            $store->resolveAccessToken('amc_' . str_repeat('A', 43), 9)
        );
    }

    /**
     * @dataProvider deadTokens
     */
    public function testDeadTokensResolveToNothing(array $override, int $consumer): void
    {
        $store = $this->store();
        $this->row = self::tokenRow($override);

        self::assertNull($store->resolveAccessToken('amc_' . str_repeat('A', 43), $consumer));
    }

    /** @return array<string, array{array<string, mixed>, int}> */
    public static function deadTokens(): array
    {
        return [
            'grant revoked'       => [['revoked_at' => '2026-10-01 00:00:00'], 9],
            'another integration' => [[], 10],
            'expired'             => [['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], 9],
        ];
    }

    public function testMalformedTokenIsNotLookedUp(): void
    {
        $store = $this->store();
        $this->connection->expects(self::never())->method('fetchRow');

        self::assertNull($store->resolveAccessToken('eyJhbGciOiJIUzI1NiJ9.a.b', 9));
        self::assertNull($store->resolveAccessToken('amc_short', 9));
    }
}
