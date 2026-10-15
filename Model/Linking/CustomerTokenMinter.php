<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Linking;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Issues the short-lived MCP-only token a connector sends as X-Customer-Token.
 *
 * NOT a Magento customer token (see LinkStore::issueAccessToken() for why):
 * it works at /mcp only, next to the Integration token of the consumer that
 * holds the grant, and dies with the grant.
 *
 * Refuses a customer who is gone, locked out, or on another website of a
 * per-website store.
 *
 * @since 2.4.0
 */
class CustomerTokenMinter
{
    public const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly LinkStore $linkStore,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AuthenticationInterface $authentication,
        private readonly Share $accountShare
    ) {
    }

    /**
     * @return array{token: string, expires_in: int}|null null when the customer may not be acted for
     */
    public function mint(int $grantId, int $customerId, int $websiteId, int $ttlSeconds = self::DEFAULT_TTL): ?array
    {
        if (!$this->mayActFor($customerId, $websiteId)) {
            return null;
        }
        $ttl = max(300, min($ttlSeconds, 86400));

        return [
            'token'      => $this->linkStore->issueAccessToken($grantId, $ttl),
            // A little less than the real lifetime, so the connector renews
            // before the store starts refusing the token.
            'expires_in' => $ttl - 60,
        ];
    }

    public function mayActFor(int $customerId, int $websiteId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return false;
        }
        if ($this->accountShare->isWebsiteScope() && (int) $customer->getWebsiteId() !== $websiteId) {
            return false;
        }
        return !$this->authentication->isLocked($customerId);
    }
}
