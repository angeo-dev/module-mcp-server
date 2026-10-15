<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Security;

use Angeo\McpServer\Api\CallerContextInterface;

/**
 * Request-scoped caller identity. Shared instance (Magento's default), written
 * once by the /mcp controller or the CLI test command, read by tools.
 *
 * Starts anonymous. The setters are deliberately not on the interface: only
 * the transport layer may decide who the caller is.
 *
 * @since 2.3.0
 */
class CallerContext implements CallerContextInterface
{
    private ?int $customerId = null;
    private bool $integration = false;

    /**
     * Record the result of authentication. Pass a customer id only after the
     * token was validated by Magento's token services.
     */
    public function authenticate(?int $customerId, bool $integration): void
    {
        $this->customerId = $customerId !== null && $customerId > 0 ? $customerId : null;
        $this->integration = $integration;
    }

    /** Back to anonymous. Used by the CLI command between runs and by tests. */
    public function reset(): void
    {
        $this->customerId = null;
        $this->integration = false;
    }

    public function getAuthType(): string
    {
        if ($this->customerId !== null) {
            return self::AUTH_CUSTOMER;
        }
        return $this->integration ? self::AUTH_INTEGRATION : self::AUTH_ANONYMOUS;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function isCustomer(): bool
    {
        return $this->customerId !== null;
    }

    public function hasIntegration(): bool
    {
        return $this->integration;
    }
}
