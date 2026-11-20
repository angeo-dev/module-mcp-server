<?php
declare(strict_types=1);

namespace Angeo\McpServer\Model\Discovery;

use Angeo\McpServer\Model\Config;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Who this MCP server is, in one place: `server/discover`, `initialize` and
 * the Server Card all read it, so they can never disagree (SEP-2127 asks the
 * card not to contradict what the live server reports).
 *
 *  - name:    reverse-DNS of the store's own domain + "/mcp", e.g.
 *             "dev.angeo.demo/mcp" for https://demo.angeo.dev/. Each store
 *             has its own name: the server describes this store, not the
 *             module. A store view on the same domain under its own path
 *             ("/de/") gets "-<store code>" so two views never share a name.
 *             Discovery → Server Name overrides it.
 *  - title:   the store name shoppers know (Agent presentation → Store Name,
 *             else the store/website name), else the domain.
 *  - version: the module version.
 *
 * The module that serves the store is named in the card's `repository`,
 * not in `name`: angeo.dev is the author, not the server.
 *
 * @api
 * @since 2.5.0
 */
class ServerIdentity
{
    public const NAME_PATTERN = '#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#';

    public function __construct(private readonly Config $config)
    {
    }

    public function name(StoreInterface $store): string
    {
        $override = $this->config->getServerNameOverride($store);
        if ($override !== '' && preg_match(self::NAME_PATTERN, $override) === 1) {
            return $override;
        }

        return self::nameFromUrl(self::baseUrl($store), (string) $store->getCode());
    }

    public function title(StoreInterface $store): string
    {
        $label = $this->config->getStoreLabel($store);
        if ($label !== '') {
            return $label;
        }
        $host = (string) parse_url(self::baseUrl($store), PHP_URL_HOST);

        return $host !== '' ? $host : 'Magento store';
    }

    public function version(): string
    {
        return Config::MODULE_VERSION;
    }

    /** @return array{name:string,title:string,version:string} */
    public function serverInfo(StoreInterface $store): array
    {
        return ['name' => $this->name($store), 'title' => $this->title($store), 'version' => $this->version()];
    }

    /**
     * "https://www.shop.example.com/" → "com.example.shop/mcp";
     * "https://shop.example.com/de/" + "de" → "com.example.shop/mcp-de".
     */
    public static function nameFromUrl(string $baseUrl, string $storeCode): string
    {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $host = (string) (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host);
        }
        $host = trim((string) preg_replace('/[^a-z0-9.-]/', '', $host), '.-');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ($host === '') {
            $host = 'localhost';
        }
        $namespace = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? $host
            : implode('.', array_reverse(explode('.', $host)));

        $server = 'mcp';
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $code = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '', $storeCode));
        if ($path !== '' && $code !== '') {
            $server .= '-' . $code;
        }

        return $namespace . '/' . $server;
    }

    /** The store's secure base URL ("https://shop.example.com/"). */
    public static function baseUrl(StoreInterface $store): string
    {
        try {
            return (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, true);
        } catch (\Throwable) {
            // fall through
        }

        return '';
    }
}
