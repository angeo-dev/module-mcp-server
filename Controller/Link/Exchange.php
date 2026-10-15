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
use Angeo\McpServer\Model\Linking\Secret;
use Angeo\McpServer\Model\Security\TokenValidator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /mcp/link/exchange  {code, code_verifier, redirect_uri}
 *   → {grant, customer_token, expires_in, customer_label}
 *
 * Redeems the one-time code the shopper's browser carried back to the
 * connector. The new grant is bound to the calling integration.
 *
 * @since 2.4.0
 */
class Exchange extends AbstractLinkApi
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
        private readonly CustomerTokenMinter $minter,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($request, $jsonFactory, $config, $storeManager, $tokenValidator, $filesystem, $logger);
    }

    protected function handle(array $body, int $consumerId, StoreInterface $store): Json
    {
        $redeemed = $this->linkStore->redeemCode(
            self::string($body, 'code', 100),
            self::string($body, 'redirect_uri'),
            self::string($body, 'code_verifier', 128)
        );
        if ($redeemed === null || $redeemed['website_id'] !== (int) $store->getWebsiteId()) {
            return $this->error('invalid_grant', 400);
        }

        if (!$this->minter->mayActFor($redeemed['customer_id'], $redeemed['website_id'])) {
            return $this->error('invalid_grant', 400);
        }

        $grant = $this->linkStore->createGrant(
            $redeemed['customer_id'],
            $redeemed['website_id'],
            $consumerId,
            $this->config->getLinkingClientLabel($store)
        );
        $grantId = (int) (Secret::parseGrant($grant)['id'] ?? 0);
        $token = $this->minter->mint(
            $grantId,
            $redeemed['customer_id'],
            $redeemed['website_id'],
            $this->config->getLinkingTokenTtl($store)
        );
        if ($token === null) {
            return $this->error('invalid_grant', 400);
        }

        return $this->ok([
            'grant'          => $grant,
            'customer_token' => $token['token'],
            'expires_in'     => $token['expires_in'],
            'customer_label' => $this->label($redeemed['customer_id']),
        ]);
    }

    /** "j…@example.com" — enough for the connector to show whose account is linked. */
    private function label(int $customerId): string
    {
        try {
            $email = (string) $this->customerRepository->getById($customerId)->getEmail();
        } catch (\Throwable) {
            return '';
        }
        $at = strrpos($email, '@');
        return $at === false || $at === 0 ? '' : mb_substr($email, 0, 1) . '…' . substr($email, $at);
    }
}
