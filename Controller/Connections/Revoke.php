<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Controller\Connections;

use Angeo\McpServer\Model\Linking\LinkStore;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Disconnect one AI assistant. The form key is checked by Magento's CSRF
 * validator; the grant is revoked only if it belongs to this customer.
 * The connector's next token request then fails, within the lifetime of the
 * token it already holds (one hour by default).
 *
 * @since 2.4.0
 */
class Revoke implements HttpPostActionInterface, AccountInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly LinkStore $linkStore,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute()
    {
        $revoked = $this->linkStore->revoke(
            (int) $this->request->getParam('grant_id'),
            (int) $this->customerSession->getCustomerId()
        );
        $revoked
            ? $this->messageManager->addSuccessMessage(__('The AI assistant was disconnected.'))
            : $this->messageManager->addErrorMessage(__('That connection was not found.'));

        return $this->redirectFactory->create()->setPath('angeo_mcp/connections');
    }
}
