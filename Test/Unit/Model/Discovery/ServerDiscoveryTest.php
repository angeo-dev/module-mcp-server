<?php
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Discovery;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Discovery\ServerCardBuilder;
use Angeo\McpServer\Model\Discovery\ServerIdentity;
use Angeo\McpServer\Model\Protocol\McpServer;
use Angeo\McpServer\Observer\AiCatalogEntry;
use PHPUnit\Framework\TestCase;

/**
 * 2.5.0: server identity, the Server Card (SEP-2127, schema v1) and the AI
 * Catalog entry.
 */
class ServerDiscoveryTest extends TestCase
{
    /**
     * @dataProvider names
     */
    public function testNameIsReverseDnsOfTheStoreDomain(string $url, string $code, string $expected): void
    {
        $name = ServerIdentity::nameFromUrl($url, $code);
        self::assertSame($expected, $name);
        self::assertMatchesRegularExpression(ServerIdentity::NAME_PATTERN, $name);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function names(): array
    {
        return [
            'subdomain'        => ['https://demo.angeo.dev/', 'default', 'dev.angeo.demo/mcp'],
            'www dropped'      => ['https://www.shop.example.com/', 'default', 'com.example.shop/mcp'],
            'store path'       => ['https://shop.example.com/de/', 'de', 'com.example.shop/mcp-de'],
            'port ignored'     => ['https://shop.example.com:8443/', 'default', 'com.example.shop/mcp'],
            'ip kept in order' => ['http://127.0.0.1/', 'default', '127.0.0.1/mcp'],
            'odd code cleaned' => ['https://a.example/x/', 'de ch!', 'example.a/mcp-dech'],
            'no host'          => ['', 'default', 'localhost/mcp'],
        ];
    }

    public function testCardHasTheRequiredFieldsAndNoToolsOrCapabilities(): void
    {
        $card = ServerCardBuilder::assemble('dev.angeo.demo/mcp', 'Angeo Demo Store', Config::MODULE_VERSION,
            'https://demo.angeo.dev/', false);

        self::assertSame(ServerCardBuilder::SCHEMA, $card['$schema']);
        self::assertSame('dev.angeo.demo/mcp', $card['name']);
        self::assertSame(Config::MODULE_VERSION, $card['version']);
        self::assertSame('Angeo Demo Store', $card['title']);
        self::assertSame('https://demo.angeo.dev/', $card['websiteUrl']);
        self::assertSame('https://demo.angeo.dev/mcp', $card['remotes'][0]['url']);
        self::assertSame('streamable-http', $card['remotes'][0]['type']);
        self::assertSame('2026-07-28', $card['remotes'][0]['supportedProtocolVersions'][0]);
        self::assertArrayNotHasKey('headers', $card['remotes'][0]);
        self::assertArrayNotHasKey('tools', $card);
        self::assertArrayNotHasKey('capabilities', $card);
        self::assertLessThanOrEqual(100, mb_strlen($card['description']));
    }

    public function testRequiredTokenIsDeclaredWithoutAValue(): void
    {
        $card = ServerCardBuilder::assemble('com.example.shop/mcp', str_repeat('Long name ', 20), '2.5.0', 'https://shop.example.com/', true);
        $header = $card['remotes'][0]['headers'][0];

        self::assertSame('Authorization', $header['name']);
        self::assertTrue($header['isRequired']);
        self::assertTrue($header['isSecret']);
        self::assertArrayNotHasKey('value', $header);
        self::assertLessThanOrEqual(100, mb_strlen($card['title']));
        self::assertLessThanOrEqual(100, mb_strlen($card['description']));
    }

    public function testNoWebsiteUrlForPlainHttp(): void
    {
        $card = ServerCardBuilder::assemble('127.0.0.1/mcp', 'Dev', '2.5.0', 'http://127.0.0.1/', false);
        self::assertArrayNotHasKey('websiteUrl', $card);
    }

    public function testOneVersionEverywhere(): void
    {
        self::assertSame(Config::MODULE_VERSION, McpServer::SERVER_VERSION);
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/composer.json'), true);
        self::assertSame($composer['version'], Config::MODULE_VERSION);
    }

    /**
     * @dataProvider cardUrls
     */
    public function testCatalogPointsToTheUsableCard(string $mode, string $connector, ?string $expected): void
    {
        self::assertSame($expected, AiCatalogEntry::cardUrl($mode, $connector, 'https://shop.example.com/'));
    }

    /** @return array<string,array{0:string,1:string,2:?string}> */
    public static function cardUrls(): array
    {
        $proxy = 'https://mcp.angeo.dev/t/shop/mcp';
        return [
            'auto with connector'     => ['auto', $proxy, $proxy . '/server-card'],
            'auto without connector'  => ['auto', '', 'https://shop.example.com/mcp/server-card'],
            'own'                     => ['own', $proxy, 'https://shop.example.com/mcp/server-card'],
            'connector only, set'     => ['connector', $proxy, $proxy . '/server-card'],
            'connector only, not set' => ['connector', '', null],
            'none'                    => ['none', $proxy, null],
        ];
    }

    public function testCatalogIdentifierUsesTheStoreDomain(): void
    {
        self::assertSame('urn:air:shop.example.com:mcp:mcp', AiCatalogEntry::identifier('com.example.shop/mcp'));
        self::assertSame('urn:air:shop.example.com:mcp:mcp-de', AiCatalogEntry::identifier('com.example.shop/mcp-de'));
        self::assertSame('urn:air:127.0.0.1:mcp:mcp', AiCatalogEntry::identifier('127.0.0.1/mcp'));
    }

    public function testCatalogEntryMeetsArd(): void
    {
        $e = AiCatalogEntry::entry('dev.angeo.demo/mcp', 'Angeo Demo', 'https://demo.angeo.dev/mcp/server-card');
        self::assertSame('urn:air:demo.angeo.dev:mcp:mcp', $e['identifier']);
        self::assertSame('Angeo Demo — MCP server', $e['displayName']);
        self::assertSame('https://demo.angeo.dev/mcp/server-card', $e['url']);
        self::assertGreaterThanOrEqual(2, count($e['representativeQueries']));
        self::assertLessThanOrEqual(5, count($e['representativeQueries']));
        self::assertSame('Magento store — MCP server', AiCatalogEntry::entry('a.b/mcp', ' ', 'https://b.a/x')['displayName']);
    }
}
