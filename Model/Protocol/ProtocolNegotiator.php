<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Model\Protocol;

/**
 * Decides which protocol era a request belongs to, and rejects requests whose
 * HTTP headers do not match their body.
 *
 * MCP has two eras since the 2026-07-28 revision:
 *
 *  - LEGACY (2024-11-05 … 2025-11-25): the client calls `initialize` once and
 *    the negotiated version applies to what follows. Older clients may send no
 *    `MCP-Protocol-Version` header at all.
 *  - MODERN (2026-07-28): no handshake. Every request carries its version in
 *    `params._meta["io.modelcontextprotocol/protocolVersion"]`, mirrored in
 *    the `MCP-Protocol-Version` header, and its method and target in the
 *    `Mcp-Method` / `Mcp-Name` headers.
 *
 * The server speaks both. A modern client that reaches a legacy-only server
 * falls back to `initialize` on its own, so supporting both is what keeps every
 * client working during the twelve-month transition.
 *
 * Header/body agreement matters for security, not just conformance: a gateway
 * that routes or rate-limits on `Mcp-Name` must not be told one tool while the
 * server executes another. A mismatch is therefore always rejected. Missing
 * headers are rejected only in strict mode (the default), so a proxy that
 * forwards the body but drops headers can be supported deliberately.
 *
 * No Magento dependencies: unit-tested in isolation.
 *
 * @since 2.3.0
 */
class ProtocolNegotiator
{
    public const ERA_LEGACY = 'legacy';
    public const ERA_MODERN = 'modern';

    /** Revisions served without a handshake. */
    public const MODERN_VERSIONS = ['2026-07-28'];

    /** Revisions negotiated through `initialize`. Newest first. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const BASE64_SENTINEL = '/^=\?base64\?(.*)\?=$/s';

    /**
     * @param array<string, string> $headers request headers, keys lower-cased
     */
    public function negotiate(JsonRpcRequest $request, array $headers, bool $strictHeaders = true): NegotiationResult
    {
        $headerVersion = $this->header($headers, 'mcp-protocol-version');
        $metaVersion = $request->getMetaProtocolVersion();

        // The handshake itself is always legacy, whatever else is sent.
        if ($request->getMethod() === 'initialize') {
            return NegotiationResult::legacy();
        }

        // Clients from before 2025-06-18 send no version anywhere; 2025-06-18 …
        // 2025-11-25 clients send only the header.
        if ($metaVersion === null
            && ($headerVersion === null || in_array($headerVersion, self::LEGACY_VERSIONS, true))
        ) {
            return NegotiationResult::legacy();
        }

        if ($metaVersion !== null && $headerVersion !== null && $metaVersion !== $headerVersion) {
            return $this->mismatch($request, sprintf(
                'Header mismatch: MCP-Protocol-Version header "%s" does not match _meta value "%s"',
                $this->clip($headerVersion),
                $this->clip($metaVersion)
            ));
        }

        $requested = $metaVersion ?? (string) $headerVersion;

        if (!in_array($requested, self::MODERN_VERSIONS, true)) {
            if (in_array($requested, self::LEGACY_VERSIONS, true)) {
                // A legacy version named in _meta: serve it the legacy way.
                return NegotiationResult::legacy();
            }

            return NegotiationResult::failed(
                JsonRpcResponse::errorWithData(
                    $request->getId(),
                    ErrorCodes::UNSUPPORTED_PROTOCOL_VERSION,
                    sprintf('Unsupported protocol version: %s', $this->clip($requested)),
                    [
                        'supported' => array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS),
                        'requested' => $this->clip($requested),
                    ]
                ),
                400
            );
        }

        // Header requirements for notification POSTs are not defined by the
        // 2026-07-28 revision, so only requests are validated.
        if (!$request->isNotification()) {
            $failure = $this->validateRoutingHeaders($request, $headers, $headerVersion, $strictHeaders);
            if ($failure !== null) {
                return $failure;
            }
        }

        return NegotiationResult::modern($requested);
    }

    /**
     * @param array<string, string> $headers
     */
    private function validateRoutingHeaders(
        JsonRpcRequest $request,
        array $headers,
        ?string $headerVersion,
        bool $strict
    ): ?NegotiationResult {
        if ($headerVersion === null && $strict) {
            return $this->mismatch($request, 'Header mismatch: missing required MCP-Protocol-Version header');
        }

        $method = $this->header($headers, 'mcp-method');
        if ($method === null) {
            if ($strict) {
                return $this->mismatch($request, 'Header mismatch: missing required Mcp-Method header');
            }
        } elseif ($method !== $request->getMethod()) {
            return $this->mismatch($request, sprintf(
                'Header mismatch: Mcp-Method header "%s" does not match body method "%s"',
                $this->clip($method),
                $this->clip($request->getMethod())
            ));
        }

        $expectedName = $request->getRoutingName();
        $nameHeader = $this->header($headers, 'mcp-name');

        if ($expectedName === null) {
            return null;
        }

        if ($nameHeader === null) {
            return $strict
                ? $this->mismatch($request, 'Header mismatch: missing required Mcp-Name header')
                : null;
        }

        $decoded = $this->decodeHeaderValue($nameHeader);
        if ($decoded === null) {
            return $this->mismatch($request, 'Header mismatch: Mcp-Name header is malformed');
        }
        if ($decoded !== $expectedName) {
            return $this->mismatch($request, sprintf(
                'Header mismatch: Mcp-Name header "%s" does not match body value "%s"',
                $this->clip($decoded),
                $this->clip($expectedName)
            ));
        }

        return null;
    }

    /**
     * Decode a header value that may use the `=?base64?…?=` sentinel.
     * Returns null for invalid characters or undecodable Base64.
     */
    public function decodeHeaderValue(string $value): ?string
    {
        // RFC 9110 field values: visible ASCII, space, horizontal tab.
        if (preg_match('/[^\x20-\x7E\t]/', $value) === 1) {
            return null;
        }

        if (preg_match(self::BASE64_SENTINEL, $value, $m) !== 1) {
            return $value;
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return $decoded;
    }

    private function mismatch(JsonRpcRequest $request, string $message): NegotiationResult
    {
        return NegotiationResult::failed(
            JsonRpcResponse::error($request->getId(), ErrorCodes::HEADER_MISMATCH, $message),
            400
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** Error messages echo client input; keep it short. */
    private function clip(string $value): string
    {
        return mb_substr($value, 0, 80);
    }
}
