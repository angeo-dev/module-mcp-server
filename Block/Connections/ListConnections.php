<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\McpServer\Block\Connections;

use Angeo\McpServer\Model\Linking\LinkStore;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * @since 2.4.0
 */
class ListConnections extends Template
{
    /** @var string */
    protected $_template = 'Angeo_McpServer::connections/list.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly LinkStore $linkStore,
        private readonly CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{grant_id: int, client_label: string, created_at: string, last_used_at: ?string}>
     */
    public function getConnections(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        return $customerId > 0 ? $this->linkStore->activeForCustomer($customerId) : [];
    }

    public function getRevokeUrl(): string
    {
        return $this->getUrl('angeo_mcp/connections/revoke');
    }

    public function formatUtc(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return (string) __('Not yet');
        }
        return $this->formatDate($utc, \IntlDateFormatter::MEDIUM, true);
    }
}
