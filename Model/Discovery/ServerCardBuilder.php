<?php
declare(strict_types=1);

namespace Angeo\McpServer\Model\Discovery;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Protocol\ProtocolNegotiator;
use Magento\Store\Api\Data\StoreInterface;

/**
 * The MCP Server Card of this store (SEP-2127, schema v1), served at
 * GET /mcp/server-card.
 *
 * It lists no tools, resources or prompts and no capabilities: the spec
 * leaves those to tools/list and server/discover, because they can differ
 * per caller. When Require Bearer Token is on, the card says so with a
 * required, secret Authorization header — it never contains a token.
 *
 * @api
 * @since 2.5.0
 */
class ServerCardBuilder
{
    public const MEDIA_TYPE = 'application/mcp-server-card+json';
    public const SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';
    public const REPOSITORY = 'https://github.com/angeo-dev/module-mcp-server';

    public function __construct(
        private readonly ServerIdentity $identity,
        private readonly Config $config
    ) {
    }

    /** @return array<string,mixed> */
    public function build(StoreInterface $store): array
    {
        $base = ServerIdentity::baseUrl($store);
        $title = $this->identity->title($store);

        return self::assemble(
            $this->identity->name($store),
            $title,
            $this->identity->version(),
            $base,
            $this->config->isTokenRequired($store)
        );
    }

    /**
     * Pure part, unit tested against the published schema.
     *
     * @return array<string,mixed>
     */
    public static function assemble(string $name, string $title, string $version, string $baseUrl, bool $tokenRequired): array
    {
        $base = rtrim($baseUrl, '/') . '/';
        $remote = [
            'type' => 'streamable-http',
            'url' => $base . 'mcp',
            'supportedProtocolVersions' => array_values(array_merge(
                ProtocolNegotiator::MODERN_VERSIONS,
                ProtocolNegotiator::LEGACY_VERSIONS
            )),
        ];
        if ($tokenRequired) {
            $remote['headers'] = [[
                'name' => 'Authorization',
                'description' => 'Access token of a Magento Integration that the store owner issued.',
                'isRequired' => true,
                'isSecret' => true,
            ]];
        }

        $card = [
            '$schema' => self::SCHEMA,
            'name' => $name,
            'version' => $version,
            // The schema allows 100 characters for each.
            'description' => mb_substr(sprintf('Product catalog and store information of %s.', $title), 0, 100),
            'title' => mb_substr($title, 0, 100),
            'repository' => ['url' => self::REPOSITORY, 'source' => 'github'],
            'remotes' => [$remote],
        ];
        if (str_starts_with($base, 'https://')) {
            $card['websiteUrl'] = $base;
        }

        return $card;
    }
}
