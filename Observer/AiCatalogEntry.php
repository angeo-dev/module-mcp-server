<?php
declare(strict_types=1);

namespace Angeo\McpServer\Observer;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Discovery\ServerCardBuilder;
use Angeo\McpServer\Model\Discovery\ServerIdentity;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Adds this store's MCP server to the AI Catalog that angeo/module-llms-txt
 * (5.1+) serves at /.well-known/ai-catalog.json.
 *
 * Event `angeo_ai_catalog_collect`, data:
 *   - store   StoreInterface
 *   - catalog DataObject with `entries` (list of AI Catalog entries)
 * No Composer dependency in either direction: without llms-txt the event is
 * never dispatched; without this module the catalog has no MCP entry.
 *
 * Which card the entry points to (Discovery → Server Card in AI Catalog):
 *   auto       the connector's card when a Connector URL is set (that is the
 *              endpoint shoppers can actually use, with OAuth), else this
 *              store's own card
 *   own        always <store>/mcp/server-card
 *   connector  only the connector's card; nothing when no Connector URL
 *   none       no entry
 *
 * @since 2.5.0
 */
class AiCatalogEntry implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ServerIdentity $identity
    ) {
    }

    public function execute(Observer $observer): void
    {
        $store = $observer->getEvent()->getData('store');
        $catalog = $observer->getEvent()->getData('catalog');
        if (!$store instanceof StoreInterface || !$catalog instanceof DataObject || !$this->config->isEnabled($store)) {
            return;
        }
        $url = self::cardUrl(
            $this->config->getCatalogEntryMode($store),
            $this->config->getConnectorUrl($store),
            ServerIdentity::baseUrl($store)
        );
        if ($url === null) {
            return;
        }

        $entries = (array) $catalog->getData('entries');
        $entries[] = [
            'identifier' => self::identifier($this->identity->name($store)),
            'type' => ServerCardBuilder::MEDIA_TYPE,
            'url' => $url,
        ];
        $catalog->setData('entries', $entries);
    }

    public static function cardUrl(string $mode, string $connectorUrl, string $baseUrl): ?string
    {
        $own = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/mcp/server-card' : null;
        $connector = $connectorUrl !== '' ? rtrim($connectorUrl, '/') . '/server-card' : null;

        return match ($mode) {
            'none' => null,
            'own' => $own,
            'connector' => $connector,
            default => $connector ?? $own,
        };
    }

    /** "com.example.shop/mcp" → "urn:air:shop.example.com:mcp:mcp" */
    public static function identifier(string $serverName): string
    {
        [$namespace, $server] = explode('/', $serverName, 2) + [1 => 'mcp'];
        $parts = explode('.', $namespace);
        $domain = filter_var($namespace, FILTER_VALIDATE_IP) !== false ? $namespace : implode('.', array_reverse($parts));

        return 'urn:air:' . $domain . ':mcp:' . $server;
    }
}
