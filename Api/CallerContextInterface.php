<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Api;

/**
 * Who is calling the MCP endpoint in the current request.
 *
 * Filled in by the /mcp controller after authentication, before any tool runs.
 * Tools that act on a customer's own data (orders, their cart) read the
 * customer id from here. Tools that only read the public catalog ignore it.
 *
 * Inject this interface, not the implementation. It is a shared, per-request
 * object: in PHP-FPM every HTTP request starts with an anonymous context.
 *
 * Security contract:
 *  - getCustomerId() returns an id ONLY after a customer token was read AND
 *    validated (signature or row, expiry, revocation) by Magento's own token
 *    services. A tool never has to re-check the token.
 *  - A tool that needs a customer MUST fail closed when getCustomerId() is
 *    null. It must never accept a customer id, email or order number from the
 *    tool arguments as a substitute for authentication.
 *
 * @api
 * @since 2.3.0
 */
interface CallerContextInterface
{
    public const AUTH_ANONYMOUS   = 'anonymous';
    public const AUTH_INTEGRATION = 'integration';
    public const AUTH_CUSTOMER    = 'customer';

    /**
     * One of the AUTH_* constants. When both an integration token and a
     * customer token are presented (an MCP proxy acting for a signed-in
     * shopper), this is AUTH_CUSTOMER and hasIntegration() is true.
     */
    public function getAuthType(): string;

    /** Authenticated Magento customer id, or null. */
    public function getCustomerId(): ?int;

    public function isCustomer(): bool;

    /** True when a valid Integration access token was presented. */
    public function hasIntegration(): bool;
}
