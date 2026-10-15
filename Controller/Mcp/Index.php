<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Mcp;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Log\AgentCallLogger;
use Angeo\McpServer\Model\Protocol\ErrorCodes;
use Angeo\McpServer\Model\Protocol\JsonRpcRequest;
use Angeo\McpServer\Model\Protocol\JsonRpcResponse;
use Angeo\McpServer\Model\Protocol\McpServer;
use Angeo\McpServer\Model\Protocol\ProtocolException;
use Angeo\McpServer\Model\Protocol\ProtocolNegotiator;
use Angeo\McpServer\Model\Security\CallerContext;
use Angeo\McpServer\Model\Security\CustomerTokenResolver;
use Angeo\McpServer\Model\Security\RateLimiter;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /mcp — MCP Streamable HTTP endpoint (single JSON response per request;
 * no SSE streaming, which keeps Varnish/LiteSpeed/shared hosting trivial).
 *
 * Speaks both protocol eras (see ProtocolNegotiator): the stateless 2026-07-28
 * revision and the initialize-based 2024-11-05 … 2025-11-25 revisions.
 *
 * Request pipeline, in order:
 *   method → store/enabled → Origin → rate limit → authentication →
 *   body → protocol negotiation → dispatch → log
 *
 * Response headers always include Cache-Control: no-store — agents must see
 * live data, and a cached JSON-RPC response is corrupt by definition. Add a
 * Varnish VCL bypass for /mcp (see README) — full-page cache sitting in
 * front of this route is the #1 Magento-specific deployment pitfall.
 *
 * @since 1.0.0
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, HttpDeleteActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY_BYTES = 65536; // 64 KB — generous for JSON-RPC, hostile to abuse

    /** Proxy channel for a shopper's token when Authorization carries the proxy's own. */
    public const CUSTOMER_TOKEN_HEADER = 'X-Customer-Token';

    private readonly ProtocolNegotiator $negotiator;
    private readonly CallerContext $callerContext;
    private readonly CustomerTokenResolver $customerTokenResolver;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly HttpResponse $response,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly McpServer $mcpServer,
        private readonly TokenValidator $tokenValidator,
        private readonly Filesystem $filesystem,
        private readonly AgentCallLogger $callLogger,
        private readonly LoggerInterface $logger,
        ?ProtocolNegotiator $negotiator = null,
        ?CallerContext $callerContext = null,
        ?CustomerTokenResolver $customerTokenResolver = null
    ) {
        // Optional so a third-party preference or plugin built against the
        // 2.2.x constructor keeps working. The object manager is touched only
        // for an argument that was actually left out.
        $this->negotiator = $negotiator
            ?? ObjectManager::getInstance()->get(ProtocolNegotiator::class);
        $this->callerContext = $callerContext
            ?? ObjectManager::getInstance()->get(CallerContext::class);
        $this->customerTokenResolver = $customerTokenResolver
            ?? ObjectManager::getInstance()->get(CustomerTokenResolver::class);
    }

    public function execute()
    {
        $this->applyBaseHeaders();
        $this->callerContext->reset();

        // 2026-07-28 removed the GET stream and session DELETE. Older clients
        // may still try them; 405 tells them plainly this endpoint is POST-only.
        $httpMethod = strtoupper((string) $this->request->getMethod());
        if ($httpMethod !== 'POST') {
            $this->response->setHeader('Allow', 'POST', true);
            return $this->respondError(405, null, ErrorCodes::INVALID_REQUEST, 'Method not allowed: use POST');
        }

        try {
            $store = $this->storeManager->getStore();
        } catch (\Throwable) {
            return $this->respondError(500, null, ErrorCodes::INTERNAL_ERROR, 'Store resolution failed');
        }

        if (!$this->config->isEnabled($store)) {
            return $this->respondError(404, null, ErrorCodes::INVALID_REQUEST, 'MCP server is disabled');
        }

        // ── Origin (DNS-rebinding protection, required by the transport spec).
        //    AI platforms connect server-to-server and send no Origin; only
        //    browsers do, and only known origins may drive this endpoint. ───
        if (!$this->isOriginAllowed($store)) {
            return $this->respondError(403, null, ErrorCodes::INVALID_REQUEST, 'Origin not allowed');
        }

        // ── Rate limit (per client IP; token hash is appended when present so
        //    authenticated agents don't share the anonymous bucket) ─────────
        $limiter = new RateLimiter(
            $this->getRateLimitDir(),
            $this->config->getRateLimitPerMinute($store)
        );
        if (!$limiter->allow($this->getClientKey())) {
            return $this->respondError(429, null, ErrorCodes::RATE_LIMITED, 'Rate limit exceeded');
        }

        // ── Authentication ──────────────────────────────────────────────────
        $authFailure = $this->authenticate($store);
        if ($authFailure !== null) {
            return $authFailure;
        }

        // ── Parse ───────────────────────────────────────────────────────────
        $body = (string) $this->request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->respondError(413, null, ErrorCodes::INVALID_REQUEST, 'Request body too large');
        }

        try {
            $rpcRequest = JsonRpcRequest::fromJson($body);
        } catch (ProtocolException $e) {
            return $this->respondError(400, null, $e->getCode(), $e->getMessage());
        }

        // ── Protocol era and header/body agreement ──────────────────────────
        $negotiation = $this->negotiator->negotiate(
            $rpcRequest,
            $this->getProtocolHeaders(),
            $this->config->isStrictHeaders($store)
        );
        $negotiationError = $negotiation->getError();
        if ($negotiationError !== null) {
            $this->response->setHttpResponseCode($negotiation->getHttpStatus());
            $this->response->setBody($negotiationError->toJson());
            return $this->response;
        }

        // ── Dispatch ────────────────────────────────────────────────────────
        $started = microtime(true);
        $rpcResponse = $this->mcpServer->handle($rpcRequest, $store, $negotiation->getVersion());

        if ($this->config->isCallLoggingEnabled($store)) {
            $this->callLogger->log(
                $store,
                $rpcRequest,
                $rpcResponse,
                (string) $this->request->getHeader('User-Agent'),
                microtime(true) - $started,
                $this->callerContext->getAuthType(),
                $negotiation->getVersion() ?? 'legacy'
            );
        }

        if ($rpcResponse === null) {
            // Notification — accepted, no body (spec: 202).
            $this->response->setHttpResponseCode(202);
            $this->response->setBody('');
            return $this->response;
        }

        // 2026-07-28: an unknown method is HTTP 404 with -32601, so a client
        // can tell "this server lacks the method" from "this is not an MCP
        // endpoint". Legacy clients keep the 200 they have always received.
        $status = $negotiation->isModern() && $rpcResponse->getErrorCode() === ErrorCodes::METHOD_NOT_FOUND
            ? 404
            : 200;

        $this->response->setHttpResponseCode($status);
        $this->response->setBody($rpcResponse->toJson());
        return $this->response;
    }

    /**
     * Fill the CallerContext. Returns an error response, or null to continue.
     *
     * Two credentials, two jobs:
     *  - `Authorization: Bearer` — an Integration access token (an agent
     *    platform or MCP proxy). A storefront customer token is accepted here
     *    only with *Accept Storefront Customer Tokens* (testing).
     *  - `X-Customer-Token` — the shopper, next to the proxy's Integration
     *    token: an MCP-only token from account linking (`amc_…`), bound to
     *    that integration. Storefront tokens only with the testing setting.
     *
     * `Require Bearer Token` keeps its 2.x meaning: a valid INTEGRATION token
     * is needed. A customer token alone proves a store account, not an
     * approved agent platform.
     */
    private function authenticate(StoreInterface $store): ?HttpResponse
    {
        $bearer = $this->getBearerToken();
        $consumerId = $this->tokenValidator->consumerIdFor($bearer);
        $hasIntegration = $consumerId !== null;

        if ($this->config->isTokenRequired($store) && !$hasIntegration) {
            $this->response->setHeader('WWW-Authenticate', 'Bearer', true);
            return $this->respondError(401, null, ErrorCodes::UNAUTHORIZED, 'Invalid or missing token');
        }

        $customerId = null;
        if ($this->config->isCustomerAuthEnabled($store)) {
            $storefrontAllowed = $this->config->isStorefrontCustomerTokenAllowed($store);
            $headerToken = $this->getCustomerTokenHeader();

            if ($headerToken !== null) {
                if (CustomerTokenResolver::isMcpToken($headerToken)) {
                    // MCP-only token: valid solely next to the Integration
                    // token of the consumer that holds the grant.
                    $customerId = $consumerId !== null
                        ? $this->customerTokenResolver->resolveMcpToken(
                            $headerToken,
                            $consumerId,
                            (int) $store->getWebsiteId()
                        )
                        : null;
                } elseif ($storefrontAllowed) {
                    $customerId = $this->customerTokenResolver->resolve($headerToken);
                }

                // An explicit customer credential that does not verify is an
                // error, never a silent downgrade to anonymous.
                if ($customerId === null) {
                    $this->response->setHeader('WWW-Authenticate', 'Bearer error="invalid_token"', true);
                    return $this->respondError(401, null, ErrorCodes::UNAUTHORIZED, 'Invalid or expired customer token');
                }
            } elseif (!$hasIntegration && $bearer !== null && $storefrontAllowed) {
                $customerId = $this->customerTokenResolver->resolve($bearer);
            }
        }

        $this->callerContext->authenticate($customerId, $hasIntegration);
        return null;
    }

    private function applyBaseHeaders(): void
    {
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->response->setHeader('Cache-Control', 'no-store', true);
        $this->response->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->response->setHeader('X-Robots-Tag', 'noindex', true);
        // The answer differs per credential once customer tools exist.
        $this->response->setHeader('Vary', 'Authorization, ' . self::CUSTOMER_TOKEN_HEADER, true);
    }

    private function respondError(int $httpCode, string|int|null $id, int $rpcCode, string $message): HttpResponse
    {
        $this->response->setHttpResponseCode($httpCode);
        $this->response->setBody(JsonRpcResponse::error($id, $rpcCode, $message)->toJson());
        return $this->response;
    }

    /**
     * Allowed: no Origin header; the store's own base URLs; configured extras.
     */
    private function isOriginAllowed(StoreInterface $store): bool
    {
        $origin = $this->request->getHeader('Origin');
        if (!is_string($origin) || trim($origin) === '') {
            return true;
        }

        // The literal "null" (sandboxed iframes, file:// pages) is an origin
        // that matches nothing, so it falls through to a rejection here.
        $normalised = Config::normaliseOrigin($origin);
        if ($normalised === null) {
            return false;
        }

        $allowed = $this->config->getAllowedOrigins($store);
        foreach ([false, true] as $secure) {
            try {
                $own = Config::normaliseOrigin((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure));
            } catch (\Throwable) {
                $own = null;
            }
            if ($own !== null) {
                $allowed[] = $own;
            }
        }

        return in_array($normalised, $allowed, true);
    }

    /** @return array<string, string> lower-cased names */
    private function getProtocolHeaders(): array
    {
        $headers = [];
        foreach (['MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'] as $name) {
            $value = $this->request->getHeader($name);
            if (is_string($value)) {
                $headers[strtolower($name)] = $value;
            }
        }
        return $headers;
    }

    private function getBearerToken(): ?string
    {
        $header = (string) $this->request->getHeader('Authorization');
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : null;
    }

    private function getCustomerTokenHeader(): ?string
    {
        $value = $this->request->getHeader(self::CUSTOMER_TOKEN_HEADER);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        // Accept a bare token or "Bearer <token>".
        if (preg_match('/^Bearer\s+(\S+)$/i', $value, $m) === 1) {
            $value = $m[1];
        }
        return $value === '' ? null : $value;
    }

    private function getClientKey(): string
    {
        // REMOTE_ADDR only — X-Forwarded-For is attacker-controlled unless the
        // operator terminates at a trusted proxy; document, don't guess.
        $ip = (string) ($this->request->getServer('REMOTE_ADDR') ?? 'unknown');
        $token = $this->getCustomerTokenHeader() ?? $this->getBearerToken();
        return $token === null ? $ip : $ip . '|' . hash('sha256', $token);
    }

    private function getRateLimitDir(): string
    {
        return $this->filesystem
            ->getDirectoryWrite(DirectoryList::VAR_DIR)
            ->getAbsolutePath('angeo/mcp/ratelimit');
    }

    // ── CSRF: machine-to-machine JSON endpoint, no session, no form key ─────

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
