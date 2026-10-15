<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Link;

use Angeo\McpServer\Block\Link\Consent;
use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Linking\LinkRequest;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /mcp/link — the shopper approves an MCP connector acting for them.
 *
 * Not signed in: the exact URL is kept as the after-login target and the
 * shopper is sent to the store's normal login page. Magento brings them back
 * here after login whatever the "redirect to dashboard" setting.
 *
 * @since 2.4.0
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly UrlInterface $url
    ) {
    }

    public function execute()
    {
        $store = $this->storeManager->getStore();
        if (!$this->config->isEnabled($store) || !$this->config->isLinkingEnabled($store)) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        $page = $this->pageFactory->create();
        // Clickjacking: this page grants access with one click, so it must
        // never render inside a frame. A separate header line (replace=false)
        // adds to Magento's own CSP instead of overwriting it; browsers
        // enforce every CSP header they receive.
        $page->setHeader('Content-Security-Policy', "frame-ancestors 'none'", false);
        $page->setHeader('X-Frame-Options', 'DENY', true);
        $page->setHeader('Referrer-Policy', 'no-referrer', true);
        $page->getConfig()->getTitle()->set((string) __('Connect your AI assistant'));
        $block = $page->getLayout()->getBlock('angeo.mcp.link.consent');
        $block = $block instanceof Consent ? $block : null;

        try {
            $link = LinkRequest::fromParams(
                (array) $this->request->getParams(),
                $this->config->getLinkingRedirectUris($store)
            );
        } catch (\InvalidArgumentException $e) {
            if ($block !== null) {
                $block->setData('error_message', $e->getMessage());
            }
            return $page;
        }

        if (!$this->customerSession->isLoggedIn()) {
            $this->customerSession->setBeforeAuthUrl($this->url->getCurrentUrl());
            return $this->redirectFactory->create()->setPath('customer/account/login');
        }

        if ($block !== null) {
            $block->setData('link_fields', $link->toFormFields());
            $block->setData('client_label', $this->config->getLinkingClientLabel($store));
            $block->setData('store_label', $this->config->getStoreLabel($store) ?: (string) $store->getName());
            $block->setData('customer_email', (string) $this->customerSession->getCustomer()->getEmail());
        }
        return $page;
    }
}
