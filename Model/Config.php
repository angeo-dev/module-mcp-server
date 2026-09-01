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
    public const MODULE_VERSION = '1.3.0';

    public const XML_PATH_ENABLED        = 'angeo_mcp/general/enabled';
    public const XML_PATH_REQUIRE_TOKEN  = 'angeo_mcp/general/require_token';
    public const XML_PATH_LOG_CALLS      = 'angeo_mcp/general/log_calls';
    public const XML_PATH_CUSTOMER_GROUP = 'angeo_mcp/catalog/customer_group_id';
    public const XML_PATH_DEFAULT_PAGE   = 'angeo_mcp/catalog/default_page_size';
    public const XML_PATH_MAX_PAGE       = 'angeo_mcp/catalog/max_page_size';
    public const XML_PATH_RATE_LIMIT     = 'angeo_mcp/security/rate_limit_per_minute';

    /**
     * Agent-facing presentation. These decide what a model reads about this
     * store BEFORE it decides whether to use the connector at all, so they
     * belong to the same config surface as the tools themselves.
     */
    public const XML_PATH_INSTRUCTIONS   = 'angeo_mcp/agent/instructions';
    public const XML_PATH_STORE_LABEL    = 'angeo_mcp/agent/store_label';
    public const XML_PATH_ANCHOR_DESC    = 'angeo_mcp/agent/anchor_descriptions';
    public const XML_PATH_TOOL_TITLES    = 'angeo_mcp/agent/tool_titles';

    /**
     * Claude Connector. Ported from the widget branch in 2.0.0.
     *
     * Deliberately has NO default: the URL is issued by whichever MCP
     * provider the merchant uses, and a wrong one sends shoppers to a
     * connector that is not theirs. Empty means the button does not render.
     */
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

    /**
     * Merchant-supplied replacement for the generated agent instructions.
     * Empty (the default) means "generate them from the store and its tools".
     */
    public function getInstructions(?StoreInterface $store = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_INSTRUCTIONS,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        ));
    }

    /**
     * How the store should be named to an agent.
     *
     * Falls back to the store view name, then the website name. The Magento
     * default "Default Store View" is deliberately rejected: it tells a model
     * nothing and cannot be matched against anything a shopper would type.
     */
    /**
     * Store view / website names Magento ships with. None of these is a name a
     * shopper would ever type, so none of them is usable as an anchor for an
     * agent: matching "check Acme" against "Default Store View" is impossible.
     */
    private const GENERIC_NAMES = [
        'default', 'default store view', 'default store', 'main website',
        'main website store', 'store view', 'main store', 'base',
    ];

    public function getStoreLabel(StoreInterface $store): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_LABEL,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ));
        if ($configured !== '') {
            return $configured;
        }

        $candidates = [trim((string) $store->getName())];

        try {
            $candidates[] = trim((string) $store->getGroup()->getName());
        } catch (\Throwable) {
            // Not resolvable in every context; the next candidate covers us.
        }
        try {
            $candidates[] = trim((string) $store->getWebsite()->getName());
        } catch (\Throwable) {
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && !self::isGenericName($candidate)) {
                return $candidate;
            }
        }

        // Everything is still at its Magento default. Returning "Default Store
        // View" here would put a meaningless name in front of every agent, so
        // return nothing instead: the instructions fall back to "this store",
        // which at least does not read as a misconfiguration.
        return '';
    }

    private static function isGenericName(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), self::GENERIC_NAMES, true);
    }

    /** Append the store name to each tool description in tools/list. */
    public function isDescriptionAnchoringEnabled(?StoreInterface $store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ANCHOR_DESC,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
    }

    /** Generate a human-readable `title` annotation when a tool omits one. */
    public function areToolTitlesEnabled(?StoreInterface $store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TOOL_TITLES,
            ScopeInterface::SCOPE_STORE,
            $store?->getId()
        );
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
