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
 * POST /mcp/link/revoke  {grant}  → {ok: true}
 *
 * The connector ends a link, e.g. when the shopper disconnects it in their AI
 * client. Always answers 200 for a well-formed call, so the endpoint cannot
 * be used to test whether a grant exists.
 *
 * @since 2.4.0
 */
class Revoke extends AbstractLinkApi
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        StoreManagerInterface $storeManager,
        TokenValidator $tokenValidator,
        Filesystem $filesystem,
        LoggerInterface $logger,
        private readonly LinkStore $linkStore
    ) {
        parent::__construct($request, $jsonFactory, $config, $storeManager, $tokenValidator, $filesystem, $logger);
    }

    protected function handle(array $body, int $consumerId, StoreInterface $store): Json
    {
        $this->linkStore->revokeByConsumer(self::string($body, 'grant', 120), $consumerId);
        return $this->ok(['ok' => true]);
    }
}
