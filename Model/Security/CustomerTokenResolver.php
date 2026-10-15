<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Security;

use Angeo\McpServer\Model\Linking\CustomerTokenMinter;
use Angeo\McpServer\Model\Linking\LinkStore;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Api\UserTokenValidatorInterface;

/**
 * Turns a Magento customer access token into a customer id.
 *
 * Uses Magento's own token services, so it accepts exactly what the core REST
 * and GraphQL APIs accept: JWT tokens (the 2.4.4+ default) and opaque tokens,
 * with signature, expiry and revocation checked by core code.
 *
 * Tokens are obtained the usual ways, e.g. `POST /rest/V1/integration/customer/token`
 * or the GraphQL `generateCustomerToken` mutation, or minted by an OAuth-capable
 * MCP proxy that signs the shopper in.
 *
 * @since 2.3.0
 */
class CustomerTokenResolver
{
    /** Tokens longer than this are not Magento tokens; refuse before parsing. */
    private const MAX_TOKEN_LENGTH = 4096;

    private readonly LinkStore $linkStore;
    private readonly CustomerTokenMinter $minter;

    public function __construct(
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly UserTokenValidatorInterface $tokenValidator,
        ?LinkStore $linkStore = null,
        ?CustomerTokenMinter $minter = null
    ) {
        $this->linkStore = $linkStore ?? ObjectManager::getInstance()->get(LinkStore::class);
        $this->minter = $minter ?? ObjectManager::getInstance()->get(CustomerTokenMinter::class);
    }

    /**
     * Customer id for an MCP-only token (account linking, 2.4.0), or null.
     *
     * Valid only together with the Integration token of the consumer that
     * holds the grant: a stolen MCP token alone opens nothing.
     *
     * @since 2.4.0
     */
    public function resolveMcpToken(string $token, int $consumerId, int $websiteId): ?int
    {
        $grant = $this->linkStore->resolveAccessToken($token, $consumerId);
        if ($grant === null || $grant['website_id'] !== $websiteId) {
            return null;
        }
        return $this->minter->mayActFor($grant['customer_id'], $grant['website_id']) ? $grant['customer_id'] : null;
    }

    public static function isMcpToken(string $token): bool
    {
        return str_starts_with($token, LinkStore::TOKEN_PREFIX);
    }

    /**
     * @return int|null customer id, or null when the token is not a valid,
     *                  unexpired, unrevoked CUSTOMER token
     */
    public function resolve(?string $token): ?int
    {
        if ($token === null || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        try {
            $userToken = $this->tokenReader->read($token);
            $this->tokenValidator->validate($userToken);
        } catch (\Throwable) {
            return null;
        }

        $context = $userToken->getUserContext();
        if ((int) $context->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            return null;
        }

        $customerId = (int) $context->getUserId();
        return $customerId > 0 ? $customerId : null;
    }
}
