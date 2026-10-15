<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Link;

use Angeo\McpServer\Model\Config;
use Angeo\McpServer\Model\Linking\LinkRequest;
use Angeo\McpServer\Model\Linking\LinkStore;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST from the approval page. The form key is checked by Magento's own CSRF
 * validator (this action does not opt out of it).
 *
 * Every parameter is validated again here: nothing from the GET page is
 * trusted just because it came back.
 *
 * @since 2.4.0
 */
class Approve implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly LinkStore $linkStore,
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute()
    {
        $store = $this->storeManager->getStore();
        if (!$this->config->isEnabled($store) || !$this->config->isLinkingEnabled($store)) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirectFactory->create()->setPath('customer/account/login');
        }

        try {
            $link = LinkRequest::fromParams(
                (array) $this->request->getParams(),
                $this->config->getLinkingRedirectUris($store)
            );
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectFactory->create()->setPath('customer/account');
        }

        if ($this->request->getParam('decision') !== 'approve') {
            return $this->redirectFactory->create()->setUrl($link->deniedRedirect());
        }

        $code = $this->linkStore->createCode(
            (int) $this->customerSession->getCustomerId(),
            (int) $store->getWebsiteId(),
            $link->redirectUri,
            $link->codeChallenge
        );

        return $this->redirectFactory->create()->setUrl($link->successRedirect($code));
    }
}
