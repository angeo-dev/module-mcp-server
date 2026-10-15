<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Connections;

use Angeo\McpServer\Model\Config;
use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * My Account → AI assistants: the connectors this customer allowed to act
 * for them. AccountInterface makes Magento require a signed-in customer.
 *
 * @since 2.4.0
 */
class Index implements HttpGetActionInterface, AccountInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory
    ) {
    }

    public function execute()
    {
        if (!$this->config->isLinkingEnabled($this->storeManager->getStore())) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('AI assistants'));
        return $page;
    }
}
