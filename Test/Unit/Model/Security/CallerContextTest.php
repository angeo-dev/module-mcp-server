<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Security;

use Angeo\McpServer\Api\CallerContextInterface;
use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Security\CallerContext;
use PHPUnit\Framework\TestCase;

class CallerContextTest extends TestCase
{
    public function testStartsAnonymous(): void
    {
        $context = new CallerContext();

        self::assertSame(CallerContextInterface::AUTH_ANONYMOUS, $context->getAuthType());
        self::assertNull($context->getCustomerId());
        self::assertFalse($context->isCustomer());
        self::assertFalse($context->hasIntegration());
    }

    public function testCustomerBehindIntegrationIsACustomer(): void
    {
        $context = new CallerContext();
        $context->authenticate(42, true);

        self::assertSame(CallerContextInterface::AUTH_CUSTOMER, $context->getAuthType());
        self::assertSame(42, $context->getCustomerId());
        self::assertTrue($context->hasIntegration());
    }

    public function testNonPositiveCustomerIdIsIgnored(): void
    {
        $context = new CallerContext();
        $context->authenticate(0, true);

        self::assertSame(CallerContextInterface::AUTH_INTEGRATION, $context->getAuthType());
        self::assertNull($context->getCustomerId());
    }

    public function testResetForgetsTheCaller(): void
    {
        $context = new CallerContext();
        $context->authenticate(42, true);
        $context->reset();

        self::assertSame(CallerContextInterface::AUTH_ANONYMOUS, $context->getAuthType());
    }

    /**
     * @dataProvider originProvider
     */
    public function testOriginNormalisation(string $input, ?string $expected): void
    {
        self::assertSame($expected, Config::normaliseOrigin($input));
    }

    /** @return array<string, array{string, ?string}> */
    public static function originProvider(): array
    {
        return [
            'default https port dropped' => ['https://Shop.Example.com:443/path?q=1', 'https://shop.example.com'],
            'custom port kept'           => ['http://localhost:6274', 'http://localhost:6274'],
            'default http port dropped'  => ['http://example.com:80', 'http://example.com'],
            'literal null'               => ['null', null],
            'not http'                   => ['file:///etc/passwd', null],
            'empty'                      => ['  ', null],
        ];
    }
}
