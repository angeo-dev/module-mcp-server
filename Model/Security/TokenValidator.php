<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Security;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Model\Oauth\TokenFactory;

/**
 * Validates a Bearer token against Magento's Integration tokens.
 *
 * The recommended operator setup (see README): System → Extensions →
 * Integrations → create "AI Agents" integration with ONLY the
 * Angeo_McpServer::agent_access ACL resource, activate it, and hand the
 * Access Token to the agent platform. Revoking the integration instantly
 * cuts agent access.
 *
 * FIXED IN 2.3.0. Customer and admin tokens live in the same `oauth_token`
 * table with the same `type = access` when a store issues opaque tokens
 * instead of JWTs. Up to 2.2.x those rows passed this check too, although the
 * docblock already promised "integration only". A row is now accepted only
 * when it belongs to an integration consumer and to no customer or admin.
 *
 * @since 1.0.0
 */
class TokenValidator
{
    public function __construct(
        private readonly TokenFactory $tokenFactory
    ) {
    }

    public function isValid(?string $bearerToken): bool
    {
        return $this->consumerIdFor($bearerToken) !== null;
    }

    /**
     * The integration consumer a valid Integration token belongs to, or null.
     *
     * Account-linking grants are bound to this id, so a grant leaked from one
     * connector cannot be redeemed through another integration.
     *
     * @since 2.4.0
     */
    public function consumerIdFor(?string $bearerToken): ?int
    {
        if ($bearerToken === null || $bearerToken === '') {
            return null;
        }

        try {
            $token = $this->tokenFactory->create()->loadByToken($bearerToken);
        } catch (\Throwable) {
            return null;
        }

        if (!$token->getId() || (string) $token->getType() !== 'access' || $token->getRevoked()) {
            return null;
        }

        // Integration tokens carry a consumer and no customer or admin owner.
        $consumerId = (int) $token->getConsumerId();
        if ($consumerId <= 0
            || (int) $token->getCustomerId() > 0
            || (int) $token->getAdminId() > 0
        ) {
            return null;
        }

        // `user_type` is set on rows written by Magento 2.4.4+. Older rows have
        // NULL there; the owner columns above already decided for those.
        $userType = $token->getData('user_type');
        if ($userType !== null && (int) $userType !== UserContextInterface::USER_TYPE_INTEGRATION) {
            return null;
        }

        return $consumerId;
    }
}
