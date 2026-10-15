<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Link;

use Angeo\McpServer\Controller\AbstractLinkApi;
use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Linking\CustomerTokenMinter;
use Angeo\McpServer\Model\Linking\LinkStore;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /mcp/link/token  {grant}  → {customer_token, expires_in}
 *
 * A fresh customer token for a linked account. Fails with invalid_grant once
 * the shopper revokes the connection in their account.
 *
 * @since 2.4.0
 */
class Token extends AbstractLinkApi
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        StoreManagerInterface $storeManager,
        TokenValidator $tokenValidator,
        Filesystem $filesystem,
        LoggerInterface $logger,
        private readonly LinkStore $linkStore,
        private readonly CustomerTokenMinter $minter
    ) {
        parent::__construct($request, $jsonFactory, $config, $storeManager, $tokenValidator, $filesystem, $logger);
    }

    protected function handle(array $body, int $consumerId, StoreInterface $store): Json
    {
        $grant = $this->linkStore->findActiveGrant(self::string($body, 'grant', 120), $consumerId);
        if ($grant === null || $grant['website_id'] !== (int) $store->getWebsiteId()) {
            return $this->error('invalid_grant', 400);
        }

        $token = $this->minter->mint(
            $grant['grant_id'],
            $grant['customer_id'],
            $grant['website_id'],
            $this->config->getLinkingTokenTtl($store)
        );
        if ($token === null) {
            return $this->error('invalid_grant', 400);
        }
        $this->linkStore->touchGrant($grant['grant_id']);

        return $this->ok(['customer_token' => $token['token'], 'expires_in' => $token['expires_in']]);
    }
}
