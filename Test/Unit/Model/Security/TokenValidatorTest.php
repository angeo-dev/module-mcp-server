<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Security;

use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\Oauth\TokenFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../_files/GeneratedFactories.php';

/**
 * Up to 2.2.x a customer's opaque token passed as an integration token.
 */
class TokenValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $row oauth_token columns
     */
    private function validatorFor(array $row): TokenValidator
    {
        $token = $this->getMockBuilder(Token::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByToken', 'getId'])
            ->getMock();
        $token->setData($row);
        $token->method('loadByToken')->willReturnSelf();
        $token->method('getId')->willReturn($row['entity_id'] ?? null);

        $factory = $this->getMockBuilder(TokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($token);

        return new TokenValidator($factory);
    }

    /** @return array<string, mixed> */
    private static function integrationRow(): array
    {
        return [
            'entity_id'   => 10,
            'type'        => 'access',
            'revoked'     => 0,
            'consumer_id' => 3,
            'customer_id' => null,
            'admin_id'    => null,
            'user_type'   => 1,
        ];
    }

    public function testActiveIntegrationTokenIsValid(): void
    {
        self::assertTrue($this->validatorFor(self::integrationRow())->isValid('tok'));
    }

    public function testPre244IntegrationRowWithoutUserTypeIsValid(): void
    {
        $row = ['user_type' => null] + self::integrationRow();
        self::assertTrue($this->validatorFor($row)->isValid('tok'));
    }

    public function testOpaqueCustomerTokenIsNotAnIntegrationToken(): void
    {
        $row = ['consumer_id' => null, 'customer_id' => 55, 'user_type' => 3] + self::integrationRow();
        self::assertFalse($this->validatorFor($row)->isValid('tok'));
    }

    public function testAdminTokenIsNotAnIntegrationToken(): void
    {
        $row = ['consumer_id' => null, 'admin_id' => 1, 'user_type' => 2] + self::integrationRow();
        self::assertFalse($this->validatorFor($row)->isValid('tok'));
    }

    public function testMismatchedUserTypeIsRejected(): void
    {
        $row = ['user_type' => 3] + self::integrationRow();
        self::assertFalse($this->validatorFor($row)->isValid('tok'));
    }

    public function testRevokedTokenIsRejected(): void
    {
        $row = ['revoked' => 1] + self::integrationRow();
        self::assertFalse($this->validatorFor($row)->isValid('tok'));
    }

    public function testRequestTokenIsRejected(): void
    {
        $row = ['type' => 'request'] + self::integrationRow();
        self::assertFalse($this->validatorFor($row)->isValid('tok'));
    }

    public function testUnknownTokenIsRejected(): void
    {
        self::assertFalse($this->validatorFor(['entity_id' => null])->isValid('tok'));
    }

    public function testEmptyTokenIsRejected(): void
    {
        $validator = $this->validatorFor(self::integrationRow());
        self::assertFalse($validator->isValid(null));
        self::assertFalse($validator->isValid(''));
    }
}
