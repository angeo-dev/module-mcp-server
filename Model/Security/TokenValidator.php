<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Security;

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
        if ($bearerToken === null || $bearerToken === '') {
            return false;
        }

        try {
            $token = $this->tokenFactory->create()->loadByToken($bearerToken);
        } catch (\Throwable) {
            return false;
        }

        // Must exist, be an integration token (not customer/admin), and not revoked.
        return $token->getId()
            && (string) $token->getType() === 'access'
            && !$token->getRevoked();
    }
}
