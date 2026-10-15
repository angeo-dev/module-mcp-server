<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Test\Unit\Model\Protocol;

use Angeo\McpServer\Model\Protocol\ErrorCodes;
use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\ProtocolNegotiator;
use PHPUnit\Framework\TestCase;

class ProtocolNegotiatorTest extends TestCase
{
    private ProtocolNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new ProtocolNegotiator();
    }

    private static function modernCall(string $tool = 'search_products', string $version = '2026-07-28'): JsonRpcRequest
    {
        return JsonRpcRequest::fromJson(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $tool,
                'arguments' => ['query' => 'mug'],
                '_meta'     => [
                    'io.modelcontextprotocol/protocolVersion' => $version,
                    'io.modelcontextprotocol/clientInfo'      => ['name' => 'test', 'version' => '1'],
                ],
            ],
        ]));
    }

    /** @return array<string, string> */
    private static function modernHeaders(string $name = 'search_products'): array
    {
        return [
            'mcp-protocol-version' => '2026-07-28',
            'mcp-method'           => 'tools/call',
            'mcp-name'             => $name,
        ];
    }

    public function testInitializeIsAlwaysLegacy(): void
    {
        $request = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}');
        $result = $this->negotiator->negotiate($request, ['mcp-protocol-version' => '2026-07-28']);

        self::assertSame(ProtocolNegotiator::ERA_LEGACY, $result->getEra());
        self::assertNull($result->getError());
    }

    public function testRequestWithoutAnyVersionIsLegacy(): void
    {
        // Clients from before 2025-06-18 send no version header at all.
        $request = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $result = $this->negotiator->negotiate($request, []);

        self::assertFalse($result->isModern());
        self::assertNull($result->getError());
    }

    public function testLegacyHeaderWithoutMetaIsLegacy(): void
    {
        $request = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $result = $this->negotiator->negotiate($request, ['mcp-protocol-version' => '2025-11-25']);

        self::assertSame(ProtocolNegotiator::ERA_LEGACY, $result->getEra());
    }

    public function testCompliantModernRequestIsAccepted(): void
    {
        $result = $this->negotiator->negotiate(self::modernCall(), self::modernHeaders());

        self::assertTrue($result->isModern());
        self::assertSame('2026-07-28', $result->getVersion());
        self::assertSame(200, $result->getHttpStatus());
    }

    public function testVersionHeaderMustMatchMeta(): void
    {
        $headers = ['mcp-protocol-version' => '2025-11-25'] + self::modernHeaders();
        $result = $this->negotiator->negotiate(self::modernCall(), $headers);

        self::assertSame(400, $result->getHttpStatus());
        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    public function testUnknownVersionListsSupportedVersions(): void
    {
        $headers = ['mcp-protocol-version' => '2099-01-01'] + self::modernHeaders();
        $result = $this->negotiator->negotiate(self::modernCall('search_products', '2099-01-01'), $headers);

        self::assertSame(400, $result->getHttpStatus());
        $error = $result->getError()?->toArray()['error'] ?? [];
        self::assertSame(ErrorCodes::UNSUPPORTED_PROTOCOL_VERSION, $error['code']);
        self::assertSame('2099-01-01', $error['data']['requested']);
        self::assertContains('2026-07-28', $error['data']['supported']);
        self::assertContains('2025-11-25', $error['data']['supported']);
    }

    public function testFinalSpecErrorCodesAreUsedNotDraftOnes(): void
    {
        // The RC used -32001/-32004; the final text moved them to -32020/-32022.
        self::assertSame(-32020, ErrorCodes::HEADER_MISMATCH);
        self::assertSame(-32021, ErrorCodes::MISSING_REQUIRED_CLIENT_CAPABILITY);
        self::assertSame(-32022, ErrorCodes::UNSUPPORTED_PROTOCOL_VERSION);
    }

    public function testStrictModeRejectsMissingMethodHeader(): void
    {
        $headers = self::modernHeaders();
        unset($headers['mcp-method']);
        $result = $this->negotiator->negotiate(self::modernCall(), $headers, true);

        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    public function testStrictModeRejectsMissingVersionHeader(): void
    {
        $headers = self::modernHeaders();
        unset($headers['mcp-protocol-version']);
        $result = $this->negotiator->negotiate(self::modernCall(), $headers, true);

        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    public function testLenientModeAcceptsMissingHeaders(): void
    {
        $result = $this->negotiator->negotiate(self::modernCall(), [], false);

        self::assertTrue($result->isModern());
    }

    public function testMismatchedMethodIsRejectedEvenWhenLenient(): void
    {
        $headers = ['mcp-method' => 'tools/list'] + self::modernHeaders();
        $result = $this->negotiator->negotiate(self::modernCall(), $headers, false);

        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    /**
     * The reason header validation exists: a gateway authorising on Mcp-Name
     * must not be told one tool while the server runs another.
     */
    public function testMismatchedToolNameIsRejected(): void
    {
        $result = $this->negotiator->negotiate(
            self::modernCall('place_order'),
            self::modernHeaders('search_products'),
            false
        );

        self::assertSame(400, $result->getHttpStatus());
        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    public function testBase64EncodedNameIsDecodedBeforeComparison(): void
    {
        $name = 'zoek_producten_ü';
        $headers = self::modernHeaders('=?base64?' . base64_encode($name) . '?=');

        $result = $this->negotiator->negotiate(self::modernCall($name), $headers);

        self::assertTrue($result->isModern());
    }

    public function testMalformedBase64NameIsRejected(): void
    {
        $result = $this->negotiator->negotiate(self::modernCall(), self::modernHeaders('=?base64?***?='));

        self::assertSame(ErrorCodes::HEADER_MISMATCH, $result->getError()?->getErrorCode());
    }

    public function testControlCharactersInHeaderAreRejected(): void
    {
        self::assertNull($this->negotiator->decodeHeaderValue("search\nproducts"));
        self::assertSame('search_products', $this->negotiator->decodeHeaderValue('search_products'));
    }

    public function testListMethodNeedsNoNameHeader(): void
    {
        $request = JsonRpcRequest::fromJson(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]));
        $result = $this->negotiator->negotiate($request, [
            'mcp-protocol-version' => '2026-07-28',
            'mcp-method'           => 'tools/list',
        ]);

        self::assertTrue($result->isModern());
    }

    public function testModernNotificationIsNotHeaderValidated(): void
    {
        $request = JsonRpcRequest::fromJson(json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/cancelled',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]));

        $result = $this->negotiator->negotiate($request, [], true);

        self::assertNull($result->getError());
    }

    public function testLegacyVersionInMetaIsServedLegacy(): void
    {
        $request = JsonRpcRequest::fromJson(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
        ]));

        $result = $this->negotiator->negotiate($request, ['mcp-protocol-version' => '2025-06-18']);

        self::assertSame(ProtocolNegotiator::ERA_LEGACY, $result->getEra());
        self::assertNull($result->getError());
    }

    public function testHeaderOnlyModernVersionIsModern(): void
    {
        // A client sending the 2026 header but no _meta is non-conforming; in
        // lenient mode it is still served in the era it asked for.
        $request = JsonRpcRequest::fromJson('{"jsonrpc":"2.0","id":4,"method":"tools/list"}');
        $result = $this->negotiator->negotiate(
            $request,
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
            false
        );

        self::assertTrue($result->isModern());
    }
}
