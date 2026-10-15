<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Security\RateLimiter;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base for the connector-facing link endpoints (server to server).
 *
 * Lives at the root of Controller/ on purpose: a class inside an action
 * folder is resolvable by URL, and an abstract one would fatal there.
 *
 * Every call must carry the connector's Integration access token. The token
 * identifies the integration consumer, and grants are bound to it: a grant
 * presented by any other integration is unknown.
 *
 * Errors use OAuth vocabulary (`invalid_request`, `invalid_grant`,
 * `invalid_client`) and never say which check failed.
 *
 * @since 2.4.0
 */
abstract class AbstractLinkApi implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY = 8192;
    private const RATE_PER_MINUTE = 120;

    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $jsonFactory,
        protected readonly Config $config,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly TokenValidator $tokenValidator,
        protected readonly Filesystem $filesystem,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        try {
            $store = $this->storeManager->getStore();
        } catch (\Throwable) {
            return $this->error('server_error', 500);
        }
        if (!$this->config->isEnabled($store) || !$this->config->isLinkingEnabled($store)) {
            return $this->error('not_found', 404);
        }

        $bearer = null;
        $header = (string) $this->request->getHeader('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            $bearer = $m[1];
        }

        $limiter = new RateLimiter(
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getAbsolutePath('angeo/mcp/ratelimit-link'),
            self::RATE_PER_MINUTE
        );
        $ip = (string) ($this->request->getServer('REMOTE_ADDR') ?? 'unknown');
        if (!$limiter->allow($ip . '|' . hash('sha256', (string) $bearer))) {
            return $this->error('slow_down', 429);
        }

        $consumerId = $this->tokenValidator->consumerIdFor($bearer);
        if ($consumerId === null) {
            return $this->error('invalid_client', 401);
        }

        $raw = (string) $this->request->getContent();
        $body = strlen($raw) <= self::MAX_BODY ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            return $this->error('invalid_request', 400);
        }

        try {
            return $this->handle($body, $consumerId, $store);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_McpServer] link API failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('server_error', 500);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    abstract protected function handle(array $body, int $consumerId, StoreInterface $store): Json;

    /** @param array<string, mixed> $data */
    protected function ok(array $data): Json
    {
        return $this->respond($data, 200);
    }

    protected function error(string $code, int $status): Json
    {
        return $this->respond(['error' => $code], $status);
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, int $status): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setData($data);
        return $result;
    }

    protected static function string(array $body, string $key, int $max = 500): string
    {
        $value = $body[$key] ?? '';
        return is_string($value) && strlen($value) <= $max ? $value : '';
    }

    // ── CSRF: machine-to-machine JSON with its own bearer auth ──────────────

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
