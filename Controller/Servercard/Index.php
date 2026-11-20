<?php
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Servercard;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Discovery\ServerCardBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /mcp/server-card — the MCP Server Card (SEP-2127).
 *
 * Public, read-only metadata, so it follows the spec's hosting rules: CORS
 * `*`, `Cache-Control: public, max-age=3600`, an ETag and 304 on a match. 404
 * when the MCP server is switched off for this store view.
 *
 * @since 2.5.0
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly HttpResponse $response,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ServerCardBuilder $builder
    ) {
    }

    public function execute()
    {
        $store = $this->storeManager->getStore();
        $this->response->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->response->setHeader('Access-Control-Allow-Origin', '*', true);
        $this->response->setHeader('Access-Control-Allow-Methods', 'GET', true);
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, If-None-Match', true);
        $this->response->setHeader('Access-Control-Expose-Headers', 'ETag', true);

        if (!$this->config->isEnabled($store)) {
            $this->response->setHttpResponseCode(404);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody('{"error":"not_found"}');
            return $this->response;
        }

        $json = (string) json_encode($this->builder->build($store), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $etag = '"' . substr(hash('sha256', $json), 0, 32) . '"';
        $this->response->setHeader('Cache-Control', 'public, max-age=3600', true);
        $this->response->setHeader('ETag', $etag, true);

        if (trim((string) $this->request->getHeader('If-None-Match')) === $etag) {
            $this->response->setHttpResponseCode(304);
            $this->response->setBody('');
            return $this->response;
        }

        $this->response->setHttpResponseCode(200);
        $this->response->setHeader('Content-Type', ServerCardBuilder::MEDIA_TYPE, true);
        $this->response->setBody($json);

        return $this->response;
    }
}
