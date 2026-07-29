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
use Angeo\McpServer\Model\Security\RateLimiter;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /mcp — MCP Streamable HTTP endpoint (single request/response mode;
 * no SSE streaming in 1.x, which keeps Varnish/LiteSpeed/shared hosting
 * compatibility trivial).
 *
 * Response headers always include Cache-Control: no-store — agents must see
 * live data, and a cached JSON-RPC response is corrupt by definition. Add a
 * Varnish VCL bypass for /mcp (see README) — full-page cache sitting in
 * front of this route is the #1 Magento-specific deployment pitfall.
 *
 * @since 1.0.0
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY_BYTES = 65536; // 64 KB — generous for JSON-RPC, hostile to abuse

    public function __construct(
        private readonly RequestInterface $request,
        private readonly HttpResponse $response,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly McpServer $mcpServer,
        private readonly TokenValidator $tokenValidator,
        private readonly Filesystem $filesystem,
        private readonly AgentCallLogger $callLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $this->applyBaseHeaders();

        try {
            $store = $this->storeManager->getStore();
        } catch (\Throwable) {
            return $this->respondError(500, null, ErrorCodes::INTERNAL_ERROR, 'Store resolution failed');
        }

        if (!$this->config->isEnabled($store)) {
            return $this->respondError(404, null, ErrorCodes::INVALID_REQUEST, 'MCP server is disabled');
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

        // ── Optional Bearer auth ────────────────────────────────────────────
        if ($this->config->isTokenRequired($store)
            && !$this->tokenValidator->isValid($this->getBearerToken())
        ) {
            $this->response->setHeader('WWW-Authenticate', 'Bearer', true);
            return $this->respondError(401, null, ErrorCodes::UNAUTHORIZED, 'Invalid or missing token');
        }

        // ── Parse & dispatch ────────────────────────────────────────────────
        $body = (string) $this->request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->respondError(413, null, ErrorCodes::INVALID_REQUEST, 'Request body too large');
        }

        try {
            $rpcRequest = JsonRpcRequest::fromJson($body);
        } catch (ProtocolException $e) {
            return $this->respondError(400, null, $e->getCode(), $e->getMessage());
        }

        $started = microtime(true);
        $rpcResponse = $this->mcpServer->handle($rpcRequest, $store);

        if ($this->config->isCallLoggingEnabled($store)) {
            $this->callLogger->log(
                $store,
                $rpcRequest,
                $rpcResponse,
                (string) $this->request->getHeader('User-Agent'),
                microtime(true) - $started
            );
        }

        if ($rpcResponse === null) {
            // Notification — accepted, no body (spec: 202).
            $this->response->setHttpResponseCode(202);
            $this->response->setBody('');
            return $this->response;
        }

        $this->response->setHttpResponseCode(200);
        $this->response->setBody($rpcResponse->toJson());
        return $this->response;
    }

    private function applyBaseHeaders(): void
    {
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->response->setHeader('Cache-Control', 'no-store', true);
        $this->response->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->response->setHeader('X-Robots-Tag', 'noindex', true);
    }

    private function respondError(int $httpCode, string|int|null $id, int $rpcCode, string $message): HttpResponse
    {
        $this->response->setHttpResponseCode($httpCode);
        $this->response->setBody(JsonRpcResponse::error($id, $rpcCode, $message)->toJson());
        return $this->response;
    }

    private function getBearerToken(): ?string
    {
        $header = (string) $this->request->getHeader('Authorization');
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : null;
    }

    private function getClientKey(): string
    {
        // REMOTE_ADDR only — X-Forwarded-For is attacker-controlled unless the
        // operator terminates at a trusted proxy; document, don't guess.
        $ip = (string) ($this->request->getServer('REMOTE_ADDR') ?? 'unknown');
        $token = $this->getBearerToken();
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
