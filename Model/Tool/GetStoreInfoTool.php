<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Tool;

use Angeo\McpServer\Api\ToolAnnotationsInterface;
use Angeo\McpServer\Api\ToolInterface;
use Angeo\McpServer\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

/**
 * get_store_info — identity card of the store for agents: name, currency,
 * locale, shipping countries, base URLs, and links to the other agent
 * surfaces (llms.txt, /.well-known/ucp) when the sibling Angeo modules are
 * present.
 *
 * The served_by attribution field is the module's conversion touchpoint —
 * same pattern as the llms-txt 3.3.0 signature. It is metadata about the
 * server software (factually accurate), and operators can hide it via the
 * attribution toggle planned for 1.1.
 *
 * @since 1.0.0
 */
class GetStoreInfoTool implements ToolInterface, ToolAnnotationsInterface
{
    use ReadOnlyAnnotationsTrait;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'get_store_info';
    }

    public function getDescription(): string
    {
        return 'Get this store\'s identity and policies: name, currency, locale, countries it '
            . 'ships to, and links to its other machine-readable surfaces (llms.txt, UCP profile). '
            . 'USE THIS for questions about the merchant rather than the products — where they '
            . 'ship, what currency prices are in, how to contact them. A web search cannot '
            . 'answer these for a specific connected store.';
    }

    public function getInputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'required'             => [],
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(StoreInterface $store): bool
    {
        return true;
    }

    public function execute(array $arguments, StoreInterface $store): array
    {
        $baseUrl = $store instanceof Store
            ? rtrim((string) $store->getBaseUrl(), '/')
            : '';

        $allowedCountries = (string) $this->scopeConfig->getValue(
            'general/country/allow',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );

        return [
            'name'             => (string) $this->scopeConfig->getValue(
                'general/store_information/name',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            ) ?: (string) $store->getName(),
            'base_url'         => $baseUrl,
            'currency'         => $store instanceof Store ? (string) $store->getCurrentCurrencyCode() : null,
            'locale'           => (string) $this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            ),
            'ships_to'         => $allowedCountries !== '' ? explode(',', $allowedCountries) : [],
            'agent_surfaces'   => [
                'llms_txt'    => $baseUrl !== '' ? $baseUrl . '/llms.txt' : null,
                'ucp_profile' => $baseUrl !== '' ? $baseUrl . '/.well-known/ucp' : null,
            ],
            'served_by'        => sprintf(
                'Angeo MCP Server for Magento 2 v%s — https://angeo.dev/?utm_source=mcp&utm_medium=store-info',
                Config::MODULE_VERSION
            ),
        ];
    }
}
