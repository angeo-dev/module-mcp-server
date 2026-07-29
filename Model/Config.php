<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model;

use Magento\Customer\Model\Group as CustomerGroup;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Central configuration helper. All nodes under angeo_mcp/*.
 *
 * @since 1.0.0
 */
class Config
{
    public const MODULE_VERSION = '1.2.0';

    public const XML_PATH_ENABLED        = 'angeo_mcp/general/enabled';
    public const XML_PATH_REQUIRE_TOKEN  = 'angeo_mcp/general/require_token';
    public const XML_PATH_LOG_CALLS      = 'angeo_mcp/general/log_calls';
    public const XML_PATH_CUSTOMER_GROUP = 'angeo_mcp/catalog/customer_group_id';
    public const XML_PATH_DEFAULT_PAGE   = 'angeo_mcp/catalog/default_page_size';
    public const XML_PATH_MAX_PAGE       = 'angeo_mcp/catalog/max_page_size';
    public const XML_PATH_RATE_LIMIT     = 'angeo_mcp/security/rate_limit_per_minute';
    public const XML_PATH_CONNECTOR_URL   = 'angeo_mcp/connector/url';
    public const XML_PATH_CONNECTOR_LABEL = 'angeo_mcp/connector/label';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?StoreInterface $store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
    }

    /**
     * When true, ALL methods (including read-only tools) require a valid
     * Integration Bearer token. Default false: read tools expose only what an
     * anonymous shopper already sees in the storefront.
     */
    public function isTokenRequired(?StoreInterface $store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
    }

    public function isCallLoggingEnabled(?StoreInterface $store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LOG_CALLS,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
    }

    public function getCustomerGroupId(StoreInterface $store): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_GROUP,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
        return $value === null ? CustomerGroup::NOT_LOGGED_IN_ID : (int) $value;
    }

    public function getDefaultPageSize(StoreInterface $store): int
    {
        $v = (int) $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_PAGE,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
        return $v > 0 ? $v : 10;
    }

    public function getMaxPageSize(StoreInterface $store): int
    {
        $v = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_PAGE,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
        return $v > 0 ? min($v, 100) : 50; // hard protocol cap at 100
    }

    public function getRateLimitPerMinute(?StoreInterface $store = null): int
    {
        $v = $this->scopeConfig->getValue(
            self::XML_PATH_RATE_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
        return $v === null ? 60 : max(0, (int) $v);
    }

    /**
     * The HTTPS MCP endpoint shoppers add to Claude.
     *
     * This is NOT this store's own /mcp path: that endpoint authenticates with
     * a bearer token and has no OAuth, so it cannot be added to an AI assistant
     * directly. It is the URL of an OAuth-capable MCP endpoint that fronts this
     * store (e.g. a provider's proxy). Empty means "no button".
     *
     * @since 1.2.0
     */
    public function getConnectorUrl(?StoreInterface $store = null): string
    {
        $url = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CONNECTOR_URL,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
        $url = trim($url);

        // Only https:// is usable — AI clients reject plain HTTP endpoints.
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return '';
        }
        return $url;
    }

    /** @since 1.2.0 */
    public function getConnectorLabel(?StoreInterface $store = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CONNECTOR_LABEL,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        ));
    }

    /** @since 1.2.0 */
    public function isConnectorConfigured(?StoreInterface $store = null): bool
    {
        return $this->getConnectorUrl($store) !== '';
    }
}
